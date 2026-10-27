<?php

namespace Cipi\Agent\Tests;

use Cipi\Agent\Providers\CipiAgentServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $home;

    protected string $mcpToken = 'cipi_mcp_testtoken';

    protected string $webhookToken = 'cipi_webhook_testtoken';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/cipi-agent-test-'.bin2hex(random_bytes(5));
        parent::setUp();
        $this->buildHome();
    }

    protected function tearDown(): void
    {
        if (isset($this->home) && is_dir($this->home)) {
            File::deleteDirectory($this->home);
        }
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [CipiAgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://myapp.example.com');
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');

        $app['config']->set('cipi.app_user', 'myapp');
        $app['config']->set('cipi.home', $this->home);
        $app['config']->set('cipi.php_version', '8.4');
        $app['config']->set('cipi.webhook_token', $this->webhookToken);
        $app['config']->set('cipi.mcp_enabled', true);
        $app['config']->set('cipi.mcp_token', $this->mcpToken);
        $app['config']->set('cipi.anonymizer_enabled', true);
        $app['config']->set('cipi.anonymizer_token', 'cipi_anonymizer_testtoken');
        $app['config']->set('cipi.deploy_branch', 'main');
        $app['config']->set('cipi.env', 'staging'); // CIPI_ENV, as Cipi 5.8 writes it
    }

    protected function buildHome(): void
    {
        foreach (['logs', 'releases/20260101120000', 'releases/20260102120000', 'shared', '.db'] as $dir) {
            mkdir("{$this->home}/{$dir}", 0755, true);
        }
        file_put_contents("{$this->home}/releases/20260101120000/REVISION", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n");
        file_put_contents("{$this->home}/releases/20260102120000/REVISION", "0123456789abcdef0123456789abcdef01234567\n");
        symlink("{$this->home}/releases/20260102120000", "{$this->home}/current");
        file_put_contents("{$this->home}/logs/deploy.log", implode("\n", [
            '[2026-10-01 10:00:00] ===== deploy start  app=myapp trigger=webhook branch=main from-release=20260101120000 =====',
            '[2026-10-01 10:00:01] task deploy:prepare',
            '[2026-10-01 10:00:42] ===== deploy OK  app=myapp release=20260102120000 duration=42s exit=0 =====',
            '',
        ]));
        file_put_contents("{$this->home}/logs/nginx-error.log", "2026/10/01 10:00:00 [error] 1#1: *1 upstream timed out\n");
    }

    /** @return array<string, string> */
    protected function mcpHeaders(array $extra = []): array
    {
        return ['Authorization' => "Bearer {$this->mcpToken}", 'Accept' => 'application/json, text/event-stream'] + $extra;
    }

    /** @param array<string, mixed> $params */
    protected function rpc(string $method, array $params = [], mixed $id = 1, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
        if ($id !== null) {
            $body['id'] = $id;
        }

        return $this->postJson('/cipi/mcp', $body, $this->mcpHeaders($headers));
    }

    /** @param array<string, mixed> $params */
    protected function modernRpc(string $method, array $params = [], mixed $id = 1, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];

        return $this->rpc($method, $params, $id, $headers + ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method]);
    }
}
