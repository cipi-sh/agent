<?php

namespace Cipi\Agent\Tests\Feature;

use Cipi\Agent\Tests\TestCase;

class WebhookTest extends TestCase
{
    private function github(array $payload, string $event = 'push'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/cipi/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => 'deliv-1',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $this->webhookToken),
        ], $body);
    }

    public function test_github_push_queues_deploy_with_claims(): void
    {
        $r = $this->github(['ref' => 'refs/heads/main', 'after' => 'abcdef1234567890abcdef1234567890abcdef12', 'pusher' => ['name' => 'octocat']]);
        $r->assertOk()->assertJsonPath('status', 'queued')->assertJsonPath('branch', 'main')->assertJsonPath('provider', 'github')->assertJsonPath('env', 'staging');

        $meta = json_decode(file_get_contents("{$this->home}/.deploy-trigger"), true);
        $this->assertSame('github', $meta['source']);
        $this->assertSame('octocat', $meta['actor']);
        $this->assertSame('main@abcdef123456', $meta['ref']);
        $this->assertSame('deliv-1', $meta['request_id']);
        $this->assertSame('main', $meta['branch']);
    }

    public function test_branch_filter_ping_and_other_events(): void
    {
        $this->github(['ref' => 'refs/heads/develop'])->assertOk()->assertJsonPath('status', 'skipped');
        $this->assertFileDoesNotExist("{$this->home}/.deploy-trigger");

        $this->github(['zen' => 'Keep it logically awesome.'], 'ping')->assertOk()->assertJsonPath('status', 'pong');
        $this->github(['action' => 'opened'], 'pull_request')->assertStatus(202)->assertJsonPath('status', 'ignored');
        $this->assertFileDoesNotExist("{$this->home}/.deploy-trigger");
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $body = json_encode(['ref' => 'refs/heads/main']);
        $this->call('POST', '/cipi/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertStatus(403);
        $this->postJson('/cipi/webhook', ['ref' => 'refs/heads/main'])->assertStatus(403);
    }

    public function test_gitlab_bitbucket_and_gitea(): void
    {
        $this->postJson('/cipi/webhook', ['ref' => 'refs/heads/main', 'checkout_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef', 'user_username' => 'gl'], [
            'X-Gitlab-Event' => 'Push Hook', 'X-Gitlab-Token' => $this->webhookToken, 'X-Gitlab-Event-UUID' => 'u-1',
        ])->assertOk()->assertJsonPath('provider', 'gitlab')->assertJsonPath('commit', 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
        $meta = json_decode(file_get_contents("{$this->home}/.deploy-trigger"), true);
        $this->assertSame('gl', $meta['actor']);
        unlink("{$this->home}/.deploy-trigger");

        $payload = ['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'cafebabecafebabecafebabecafebabecafebabe']]]]], 'actor' => ['nickname' => 'bb']];
        $body = json_encode($payload);
        $this->call('POST', '/cipi/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => 'repo:push', 'HTTP_X_HOOK_UUID' => 'h-1', 'HTTP_X_REQUEST_UUID' => 'r-1',
            'HTTP_X_HUB_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->webhookToken),
        ], $body)->assertOk()->assertJsonPath('provider', 'bitbucket')->assertJsonPath('branch', 'main');
        unlink("{$this->home}/.deploy-trigger");

        $body = json_encode(['ref' => 'refs/heads/main', 'after' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2']);
        $this->call('POST', '/cipi/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITEA_EVENT' => 'push',
            'HTTP_X_GITEA_SIGNATURE' => hash_hmac('sha256', $body, $this->webhookToken),
        ], $body)->assertOk()->assertJsonPath('provider', 'gitea');
    }
}
