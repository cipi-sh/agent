<?php

namespace Cipi\Agent\Tests\Feature;

use Cipi\Agent\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class McpToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('phone')->nullable();
            $t->string('remember_token', 100)->nullable();
            $t->timestamps();
        });
        Schema::create('sessions', function ($t) {
            $t->string('id')->primary();
            $t->text('payload');
        });
        DB::table('users')->insert([
            ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'x', 'phone' => null, 'remember_token' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Zoe', 'email' => 'zoe@example.com', 'password' => 'y', 'phone' => '123', 'remember_token' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function callTool(string $tool, array $arguments = []): array
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments])->assertOk()->json('result');
    }

    public function test_deploy_writes_trigger_with_audit_claims(): void
    {
        $result = $this->callTool('deploy', ['actor' => 'jane@example.com', 'request_id' => 'chat-42']);
        $this->assertTrue($result['structuredContent']['success']);
        $trigger = "{$this->home}/.deploy-trigger";
        $this->assertFileExists($trigger);
        $meta = json_decode(file_get_contents($trigger), true);
        $this->assertSame('mcp', $meta['source']);
        $this->assertSame('jane@example.com', $meta['actor']);
        $this->assertSame('chat-42', $meta['request_id']);
        $this->assertSame('main', $meta['ref']);
        $this->assertSame('mcp', $meta['trigger']);
        $this->assertNotEmpty($meta['ip']);

        // already queued → still success, flagged
        $result = $this->callTool('deploy');
        $this->assertTrue($result['structuredContent']['already_queued']);

        // running → refused
        rename($trigger, "{$this->home}/.deploy-trigger.run");
        $result = $this->callTool('deploy');
        $this->assertTrue($result['isError']);
        $this->assertTrue($result['structuredContent']['in_progress']);
    }

    public function test_deploy_status(): void
    {
        $s = $this->callTool('deploy_status', ['log_lines' => 10])['structuredContent'];
        $this->assertSame('20260102120000', $s['release']);
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $s['commit']);
        $this->assertSame('REVISION', $s['commit_source']);
        $this->assertCount(2, $s['releases']);
        $this->assertTrue($s['releases'][0]['current']);
        $this->assertSame('ok', $s['last_deploy']['status']);
        $this->assertSame('webhook', $s['last_deploy']['trigger']);
        $this->assertSame(42, $s['last_deploy']['duration_seconds']);
        $this->assertStringContainsString('deploy OK', $s['deploy_log']);
    }

    public function test_logs_reads_infrastructure_and_laravel_logs_with_redaction(): void
    {
        $dir = storage_path('logs');
        @mkdir($dir, 0755, true);
        file_put_contents("{$dir}/laravel.log", implode("\n", [
            '[2026-10-01 10:00:00] production.INFO: boot DB_PASSWORD=supersecret',
            '[2026-10-01 10:00:01] production.ERROR: Boom {"token":"abc"}',
            '#0 /app/vendor/x.php(1): fail()',
            '[2026-10-01 10:00:02] production.DEBUG: fine',
            '',
        ]));
        try {
            $r = $this->callTool('logs', ['type' => 'laravel', 'level' => 'error']);
            $text = $r['content'][0]['text'];
            $this->assertStringContainsString('production.ERROR: Boom', $text);
            $this->assertStringContainsString('#0 /app/vendor/x.php', $text);
            $this->assertStringNotContainsString('production.DEBUG', $text);
            $this->assertStringContainsString('Production content', $text);

            $r = $this->callTool('logs', ['type' => 'laravel', 'search' => 'boot']);
            $this->assertStringContainsString('DB_PASSWORD=[REDACTED]', $r['content'][0]['text']);
            $this->assertStringNotContainsString('supersecret', $r['content'][0]['text']);

            $r = $this->callTool('logs', ['type' => 'nginx']);
            $this->assertStringContainsString('upstream timed out', $r['content'][0]['text']);

            $r = $this->callTool('logs', ['type' => 'deploy', 'lines' => 2]);
            $this->assertStringContainsString('deploy OK', $r['content'][0]['text']);

            $r = $this->callTool('logs', ['type' => 'horizon']);
            $this->assertTrue($r['isError']);
        } finally {
            @unlink("{$dir}/laravel.log");
        }
    }

    public function test_db_query_reads_caps_and_blocks(): void
    {
        $r = $this->callTool('db_query', ['query' => 'SELECT id, email FROM users ORDER BY id']);
        $this->assertSame(2, $r['structuredContent']['row_count']);
        $this->assertStringContainsString('pat@example.com', $r['content'][0]['text']);
        $this->assertFalse($r['structuredContent']['truncated']);

        $r = $this->callTool('db_query', ['query' => 'SELECT * FROM users', 'limit' => 1]);
        $this->assertTrue($r['structuredContent']['truncated']);
        $this->assertSame(1, $r['structuredContent']['row_count']);

        $r = $this->callTool('db_query', ['query' => 'DROP TABLE users']);
        $this->assertTrue($r['isError']);
        $r = $this->callTool('db_query', ['query' => 'TRUNCATE users']);
        $this->assertTrue($r['isError']);
        $r = $this->callTool('db_query', ['query' => 'SELECT 1; SELECT 2']);
        $this->assertTrue($r['isError']);

        $r = $this->callTool('db_query', ['query' => "UPDATE users SET name = 'Patricia' WHERE id = 1"]);
        $this->assertSame(1, $r['structuredContent']['affected']);

        config()->set('cipi.mcp_db_write', false);
        $r = $this->callTool('db_query', ['query' => "UPDATE users SET name = 'x' WHERE id = 1"]);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString('read-only', $r['content'][0]['text']);
    }

    public function test_db_schema(): void
    {
        $r = $this->callTool('db_schema')['structuredContent'];
        $tables = collect($r['tables'])->keyBy('table');
        $this->assertSame(2, $tables['users']['rows']);
        $this->assertTrue($tables['sessions']['truncate_candidate']);

        $r = $this->callTool('db_schema', ['table' => 'users'])['structuredContent'];
        $this->assertSame('fakeEmail', $r['looks_personal']['email']);
        $this->assertSame('password', $r['looks_personal']['password']);
        $this->assertSame('null', $r['looks_personal']['remember_token']);
        $this->assertArrayNotHasKey('id', $r['looks_personal']);
        $this->assertContains('email', collect($r['columns'])->pluck('name')->all());

        $this->assertTrue($this->callTool('db_schema', ['table' => 'nope'])['isError']);
    }

    public function test_env_check_never_leaks_values(): void
    {
        $env = base_path('.env');
        $example = base_path('.env.example');
        $hadEnv = is_file($env);
        $old = $hadEnv ? file_get_contents($env) : null;
        file_put_contents($env, "APP_KEY=base64:secretvalue\nSTRIPE_KEY=sk_live_abc\nEMPTY_ONE=\n");
        file_put_contents($example, "APP_KEY=\nSTRIPE_KEY=\nMAIL_HOST=\n");
        try {
            $r = $this->callTool('env_check');
            $text = $r['content'][0]['text'];
            $this->assertStringNotContainsString('secretvalue', $text);
            $this->assertStringNotContainsString('sk_live_abc', $text);
            $s = $r['structuredContent'];
            $this->assertSame(['EMPTY_ONE'], $s['empty']);
            $this->assertSame(['MAIL_HOST'], $s['missing_from_env']);
            $this->assertSame(['EMPTY_ONE'], $s['not_in_example']);
        } finally {
            if ($hadEnv) {
                file_put_contents($env, $old);
            } else {
                @unlink($env);
            }
            @unlink($example);
        }
    }

    public function test_cipi_yml_tool(): void
    {
        $r = $this->callTool('cipi_yml', ['action' => 'example']);
        $this->assertStringContainsString('version: 1', $r['content'][0]['text']);

        $r = $this->callTool('cipi_yml', ['action' => 'validate', 'content' => $r['structuredContent']['yaml']]);
        $this->assertArrayNotHasKey('isError', $r);
        $this->assertTrue($r['structuredContent']['ok']);

        $r = $this->callTool('cipi_yml', ['action' => 'validate', 'content' => "version: 1\ndatabases:\n  - name: otherapp\n"]);
        $this->assertTrue($r['isError']);
        $this->assertStringContainsString("must be 'myapp' or start with 'myapp_'", $r['content'][0]['text']);

        $r = $this->callTool('cipi_yml', ['action' => 'generate']);
        $this->assertTrue($r['structuredContent']['valid']);
        $this->assertStringContainsString('php: "8.4"', $r['content'][0]['text']);
        $this->assertSame('myapp', $r['structuredContent']['facts']['app']);

        $r = $this->callTool('cipi_yml', ['action' => 'show', 'content' => "version: 1\nschedule: true\n"]);
        $this->assertTrue($r['structuredContent']['data']['schedule']);

        $r = $this->callTool('cipi_yml', ['action' => 'validate', 'file' => '/etc/hosts']);
        $this->assertTrue($r['isError']);
    }

    public function test_anonymizer_tool(): void
    {
        $r = $this->callTool('anonymizer', ['action' => 'transformations']);
        $this->assertArrayHasKey('fakeEmail', $r['structuredContent']['transformations']);

        $r = $this->callTool('anonymizer', ['action' => 'scan'])['structuredContent'];
        $this->assertSame('fakeEmail', $r['transformations']['users']['email']);
        $this->assertSame('password', $r['transformations']['users']['password']);
        $this->assertContains('sessions', $r['truncate']);

        $r = $this->callTool('anonymizer', ['action' => 'audit']);
        $this->assertTrue($r['isError']); // no config yet

        file_put_contents("{$this->home}/.db/anonymization.json", json_encode([
            'transformations' => ['users' => ['email' => 'fakeEmail', 'ghost' => 'fakeName'], 'missing_table' => ['x' => 'fakeName']],
        ]));
        $r = $this->callTool('anonymizer', ['action' => 'audit']);
        $this->assertTrue($r['isError']);
        $errors = $r['structuredContent']['errors'];
        $this->assertContains('transformations.users.ghost: column does not exist', $errors);
        $this->assertContains('transformations.missing_table: table does not exist in the database', $errors);
        $this->assertSame('password', $r['structuredContent']['suggestions']['users']['password']);

        file_put_contents("{$this->home}/.db/anonymization.json", json_encode([
            'transformations' => ['users' => ['name' => 'fakeName', 'email' => 'fakeEmail', 'password' => 'password', 'phone' => 'fakePhoneNumber', 'remember_token' => 'null']],
            'truncate' => ['sessions'],
        ]));
        $r = $this->callTool('anonymizer', ['action' => 'audit']);
        $this->assertArrayNotHasKey('isError', $r);
        $this->assertTrue($r['structuredContent']['ok']);
        $this->assertSame(0, $r['structuredContent']['summary']['columns_uncovered']);

        $r = $this->callTool('anonymizer', ['action' => 'start']);
        $this->assertTrue($r['isError']); // email required
    }
}
