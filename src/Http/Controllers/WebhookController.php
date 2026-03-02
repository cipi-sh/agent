<?php

namespace Cipi\Agent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            return response()->json(['error' => 'CIPI_APP_USER not configured'], 500);
        }

        // Extract branch from the Git provider payload
        $pushBranch = $this->extractBranch($request);
        $allowedBranch = config('cipi.deploy_branch');

        // Branch filter: skip if push doesn't match configured branch
        if ($allowedBranch && $pushBranch && $pushBranch !== $allowedBranch) {
            return response()->json([
                'status' => 'skipped',
                'reason' => "Push to '{$pushBranch}', deploy branch is '{$allowedBranch}'",
            ]);
        }

        // Write deploy trigger flag file.
        //
        // How it works:
        // - This controller only writes a small JSON file to the app user's home.
        // - A cron job (running every minute AS the app user) checks for this file.
        // - If the file exists, the cron deletes it and runs `dep deploy`.
        //
        // This avoids any sudo/permission issues: PHP-FPM writes a file inside
        // the app home (which it can do because the FPM pool runs as the app user),
        // and the cron also runs as the app user. Deployer runs as the app user.
        // No privilege escalation needed.

        $triggerFile = "/home/{$appUser}/.deploy-trigger";

        $triggerData = json_encode([
            'branch' => $pushBranch,
            'trigger' => 'webhook',
            'provider' => $this->detectProvider($request),
            'timestamp' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);

        $written = @file_put_contents($triggerFile, $triggerData);

        if ($written === false) {
            $this->log('error', "Cannot write deploy trigger to {$triggerFile}");
            return response()->json(['error' => 'Cannot write deploy trigger'], 500);
        }

        $this->log('info', "Deploy queued for '{$appUser}' (branch: {$pushBranch})");

        return response()->json([
            'status' => 'queued',
            'app' => $appUser,
            'branch' => $pushBranch,
            'message' => 'Deploy queued. It will start within 1 minute.',
        ]);
    }

    /**
     * Extract branch name from various Git provider payloads.
     */
    protected function extractBranch(Request $request): ?string
    {
        $payload = $request->all();

        // GitHub & Gitea: "ref" => "refs/heads/main"
        if (isset($payload['ref']) && str_starts_with($payload['ref'], 'refs/heads/')) {
            return substr($payload['ref'], strlen('refs/heads/'));
        }

        // GitLab: "ref" => "main" (no prefix)
        if (isset($payload['ref']) && ! str_contains($payload['ref'], '/')) {
            return $payload['ref'];
        }

        // Bitbucket: nested in push.changes
        if (isset($payload['push']['changes'][0]['new']['name'])) {
            return $payload['push']['changes'][0]['new']['name'];
        }

        return null;
    }

    /**
     * Detect which Git provider sent the webhook.
     */
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
        if ($request->hasHeader('X-Hook-UUID')) {
            return 'bitbucket';
        }

        return 'unknown';
    }

    protected function log(string $level, string $message): void
    {
        $channel = config('cipi.log_channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();
        $logger->{$level}("[Cipi Agent] {$message}");
    }
}
