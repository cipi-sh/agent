<?php

namespace Cipi\Agent\Http\Controllers;

use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\DeployTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Git push webhook → deploy trigger.
 *
 * Mirrors the receiver Cipi installs for Node apps (cipi-webhook.php): the
 * same providers, events and payload fields, so the deploy audit ledger
 * gets the same claims whichever receiver handled the push. GitHub `ping`
 * answers pong, non-push events are acknowledged and ignored, and a push to
 * another branch than CIPI_DEPLOY_BRANCH is skipped.
 */
class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        if (AppHome::user() === null) {
            return response()->json(['error' => 'CIPI_APP_USER not configured'], 500);
        }

        $provider = $this->detectProvider($request);
        $payload = $request->json()->all();

        if ($provider === 'github' && $request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['status' => 'pong', 'provider' => $provider]);
        }
        if (! $this->isPushEvent($request, $provider)) {
            return response()->json(['status' => 'ignored', 'provider' => $provider, 'reason' => 'not a push event'], 202);
        }

        $refs = $this->extractRefs($payload, $provider);
        $branches = array_values(array_filter(array_map(
            fn (string $ref) => str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : (str_contains($ref, '/') ? null : $ref),
            $refs
        )));
        $allowed = config('cipi.deploy_branch');
        $pushBranch = $branches[0] ?? null;

        if ($allowed && $branches !== [] && ! in_array($allowed, $branches, true)) {
            return response()->json([
                'status' => 'skipped',
                'reason' => "Push to '".implode(',', $branches)."', deploy branch is '{$allowed}'",
            ]);
        }
        if ($allowed && in_array($allowed, $branches, true)) {
            $pushBranch = $allowed;
        }

        $commit = $this->extractCommit($payload, $provider);
        $result = DeployTrigger::queue($provider, [
            'actor' => $this->extractActor($payload, $provider),
            'ip' => $request->ip(),
            'ref' => ($pushBranch ?? '').($commit !== '' ? '@'.substr($commit, 0, 12) : ''),
            'request_id' => $this->extractDelivery($request, $provider, $payload),
            'branch' => $pushBranch,
        ]);

        if (! $result['success']) {
            $this->log('error', $result['error'] ?? 'Cannot write deploy trigger');

            return response()->json(['error' => 'Cannot write deploy trigger'], 500);
        }

        $this->log('info', "Deploy queued for '".AppHome::user()."' (provider: {$provider}, branch: ".($pushBranch ?? '?').')');

        return response()->json([
            'status' => 'queued',
            'app' => AppHome::user(),
            'provider' => $provider,
            'branch' => $pushBranch,
            'commit' => $commit !== '' ? $commit : null,
            'message' => 'Deploy queued. It will start within 1 minute.',
        ]);
    }

    protected function detectProvider(Request $request): string
    {
        if ($request->hasHeader('X-GitHub-Event')) {
            return 'github';
        }
        if ($request->hasHeader('X-Gitlab-Event')) {
            return 'gitlab';
        }
        if ($request->hasHeader('X-Gitea-Event')) {
            return 'gitea';
        }
        if ($request->hasHeader('X-Hook-UUID') || $request->hasHeader('X-Event-Key')) {
            return 'bitbucket';
        }
        if ($request->hasHeader('X-Gitlab-Token') && ($request->json('eventType') !== null)) {
            return 'azure';
        }

        return 'webhook';
    }

    protected function isPushEvent(Request $request, string $provider): bool
    {
        return match ($provider) {
            'github' => in_array($request->header('X-GitHub-Event'), ['push', null], true),
            'gitea' => in_array($request->header('X-Gitea-Event'), ['push', null], true),
            'gitlab' => in_array($request->header('X-Gitlab-Event'), ['Push Hook', 'Tag Push Hook', null], true),
            'bitbucket' => in_array($request->header('X-Event-Key'), ['repo:push', null], true),
            'azure' => ($request->json('eventType') ?? 'git.push') === 'git.push',
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function extractRefs(array $payload, string $provider): array
    {
        $refs = [];
        if (isset($payload['ref']) && is_string($payload['ref'])) {
            $refs[] = $payload['ref'];
        }
        foreach ((array) ($payload['push']['changes'] ?? []) as $change) {
            if (is_array($change) && ($change['new']['type'] ?? '') === 'branch' && isset($change['new']['name'])) {
                $refs[] = 'refs/heads/'.$change['new']['name'];
            }
        }
        foreach ((array) ($payload['resource']['refUpdates'] ?? []) as $update) {
            if (is_array($update) && isset($update['name'])) {
                $refs[] = (string) $update['name'];
            }
        }

        return array_values(array_unique(array_filter($refs, fn ($r) => is_string($r) && $r !== '')));
    }

    /** @param array<string, mixed> $payload */
    protected function extractCommit(array $payload, string $provider): string
    {
        $candidates = [
            $payload['after'] ?? null,
            $payload['checkout_sha'] ?? null,
            $payload['head_commit']['id'] ?? null,
        ];
        foreach ((array) ($payload['push']['changes'] ?? []) as $change) {
            $candidates[] = $change['new']['target']['hash'] ?? null;
        }
        foreach ((array) ($payload['resource']['refUpdates'] ?? []) as $update) {
            $candidates[] = $update['newObjectId'] ?? null;
        }
        foreach ($candidates as $c) {
            if (is_string($c) && preg_match('/^[a-f0-9]{7,40}$/i', $c)) {
                return strtolower($c);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $payload */
    protected function extractActor(array $payload, string $provider): string
    {
        $candidates = [
            $payload['pusher']['name'] ?? null, $payload['pusher']['email'] ?? null, $payload['sender']['login'] ?? null,
            $payload['user_username'] ?? null, $payload['user_email'] ?? null, $payload['user_name'] ?? null,
            $payload['actor']['nickname'] ?? null, $payload['actor']['display_name'] ?? null,
            $payload['resource']['pushedBy']['uniqueName'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $payload */
    protected function extractDelivery(Request $request, string $provider, array $payload): string
    {
        return (string) ($request->header('X-GitHub-Delivery')
            ?? $request->header('X-Gitlab-Event-UUID')
            ?? $request->header('X-Request-UUID')
            ?? $request->header('X-Hook-UUID')
            ?? $request->header('X-Gitea-Delivery')
            ?? ($payload['id'] ?? ''));
    }

    protected function log(string $level, string $message): void
    {
        $channel = config('cipi.log_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->{$level}("[Cipi Agent] {$message}");
    }
}
