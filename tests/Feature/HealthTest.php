<?php

namespace Cipi\Agent\Tests\Feature;

use Cipi\Agent\Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_requires_token_and_reports_checks(): void
    {
        $this->getJson('/cipi/health')->assertStatus(403);

        $r = $this->getJson('/cipi/health', ['Authorization' => "Bearer {$this->webhookToken}"]);
        $r->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('app_user', 'myapp')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('checks.deploy.release', '20260102120000')
            ->assertJsonPath('checks.deploy.short_commit', '0123456')
            ->assertJsonPath('checks.deploy.branch', 'main')
            ->assertJsonPath('checks.deploy.env', 'staging')
            ->assertJsonPath('checks.deploy.last_deploy.status', 'ok');
        $this->assertFalse($r->json('checks.app.maintenance'));
    }

    public function test_failed_last_deploy_is_flagged_without_degrading_health(): void
    {
        file_put_contents("{$this->home}/logs/deploy.log", implode("\n", [
            '[2026-10-02 10:00:00] ===== deploy start  app=myapp trigger=mcp branch=main from-release=20260102120000 =====',
            '[2026-10-02 10:00:05] ===== deploy FAILED  app=myapp release=20260102120000 duration=5s exit=1 =====',
            '',
        ]));
        $this->getJson('/cipi/health', ['Authorization' => "Bearer {$this->webhookToken}"])
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.deploy.ok', true)
            ->assertJsonPath('checks.deploy.last_deploy.status', 'failed')
            ->assertJsonPath('checks.deploy.last_deploy.trigger', 'mcp');
        $this->assertStringContainsString('failed', $this->getJson('/cipi/health', ['Authorization' => "Bearer {$this->webhookToken}"])->json('checks.deploy.attention'));
    }
}
