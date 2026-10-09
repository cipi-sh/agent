<?php

namespace Cipi\Agent\Tests\Feature;

use Cipi\Agent\Mcp\Protocol;
use Cipi\Agent\Tests\TestCase;

class McpProtocolTest extends TestCase
{
    public function test_requires_token(): void
    {
        $this->postJson('/cipi/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(403);
        $this->postJson('/cipi/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Authorization' => 'Bearer wrong'])->assertStatus(403);
    }

    public function test_get_and_delete_answer_405(): void
    {
        $this->get('/cipi/mcp', $this->mcpHeaders())->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->delete('/cipi/mcp', [], $this->mcpHeaders())->assertStatus(405);
    }

    public function test_legacy_initialize_echoes_supported_version_and_falls_back_to_latest_legacy(): void
    {
        $r = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'cursor', 'version' => '1']]);
        $r->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'cipi-agent')
            ->assertJsonPath('result.serverInfo.title', 'Cipi Agent')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJsonPath('result.capabilities.resources.subscribe', false);
        $this->assertStringContainsString("'myapp'", $r->json('result.instructions'));
        $this->assertArrayNotHasKey('resultType', $r->json('result'));

        $this->rpc('initialize', ['protocolVersion' => '2024-11-05'])->assertOk()->assertJsonPath('result.protocolVersion', '2024-11-05');
        $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])->assertOk()->assertJsonPath('result.protocolVersion', Protocol::LATEST_LEGACY);
    }

    public function test_notifications_return_202_without_body(): void
    {
        $r = $this->rpc('notifications/initialized', [], null);
        $r->assertStatus(202);
        $this->assertSame('', $r->getContent());
    }

    public function test_ping_and_unknown_method_legacy(): void
    {
        $this->rpc('ping')->assertOk()->assertJsonPath('id', 1)->assertExactJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
        $this->rpc('prompts/list')->assertOk()->assertJsonPath('error.code', Protocol::METHOD_NOT_FOUND);
    }

    public function test_invalid_json_and_batch(): void
    {
        $this->call('POST', '/cipi/mcp', [], [], [], $this->serverHeaders(), '{not json')->assertStatus(400)->assertJsonPath('error.code', Protocol::PARSE_ERROR);

        $r = $this->postJson('/cipi/mcp', [
            ['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'tools/list'],
        ], $this->mcpHeaders());
        $r->assertOk();
        $this->assertCount(2, $r->json());
        $this->assertSame('a', $r->json('0.id'));
        $this->assertSame('b', $r->json('1.id'));
    }

    public function test_tools_list_is_deterministic_and_annotated(): void
    {
        $r = $this->rpc('tools/list')->assertOk();
        $tools = collect($r->json('result.tools'));
        $names = $tools->pluck('name')->all();

        $this->assertSame(['health', 'app_info', 'deploy', 'deploy_status', 'logs', 'artisan', 'db_query', 'db_schema', 'env_check', 'cipi_yml', 'anonymizer'], $names);
        $this->assertSame($names, collect($this->rpc('tools/list')->json('result.tools'))->pluck('name')->all());

        $health = $tools->firstWhere('name', 'health');
        $this->assertTrue($health['annotations']['readOnlyHint']);
        $this->assertSame('Application health', $health['title']);
        $this->assertSame('object', $health['inputSchema']['type']);
        $this->assertArrayHasKey('outputSchema', $health);

        $artisan = $tools->firstWhere('name', 'artisan');
        $this->assertFalse($artisan['annotations']['readOnlyHint']);
        $this->assertTrue($artisan['annotations']['destructiveHint']);
        $this->assertSame(['command'], $artisan['inputSchema']['required']);
    }

    public function test_tools_call_health_returns_text_and_structured_content(): void
    {
        $r = $this->rpc('tools/call', ['name' => 'health', 'arguments' => []])->assertOk();
        $this->assertSame('text', $r->json('result.content.0.type'));
        $this->assertSame('healthy', $r->json('result.structuredContent.status'));
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $r->json('result.structuredContent.checks.deploy.commit'));
        $this->assertSame('20260102120000', $r->json('result.structuredContent.checks.deploy.release'));
        $this->assertSame('ok', $r->json('result.structuredContent.checks.deploy.last_deploy.status'));
        $this->assertArrayNotHasKey('isError', $r->json('result'));
    }

    public function test_unknown_tool_is_a_protocol_error_and_tool_failures_are_isError(): void
    {
        $this->rpc('tools/call', ['name' => 'nope'])->assertOk()->assertJsonPath('error.code', Protocol::INVALID_PARAMS);

        $r = $this->rpc('tools/call', ['name' => 'artisan', 'arguments' => ['command' => 'tinker']])->assertOk();
        $this->assertTrue($r->json('result.isError'));
        $this->assertStringContainsString('blocked', $r->json('result.content.0.text'));

        $r = $this->rpc('tools/call', ['name' => 'artisan', 'arguments' => ['command' => 'migrate:fresh --force']])->assertOk();
        $this->assertTrue($r->json('result.isError'));

        $r = $this->rpc('tools/call', ['name' => 'artisan', 'arguments' => ['command' => 'list --format=json']])->assertOk();
        $this->assertArrayNotHasKey('isError', $r->json('result'));
        $this->assertSame(0, $r->json('result.structuredContent.exit_code'));
    }

    public function test_modern_discover_and_result_type(): void
    {
        $r = $this->modernRpc('server/discover')->assertOk();
        $this->assertSame('complete', $r->json('result.resultType'));
        $this->assertSame(Protocol::supported(), $r->json('result.supportedVersions'));
        $this->assertSame('cipi-agent', $r->json('result._meta')['io.modelcontextprotocol/serverInfo']['name']);

        $r = $this->modernRpc('tools/call', ['name' => 'health', 'arguments' => []], 2, ['Mcp-Name' => 'health'])->assertOk();
        $this->assertSame('complete', $r->json('result.resultType'));
        $this->assertSame('healthy', $r->json('result.structuredContent.status'));
    }

    public function test_modern_errors_map_to_http_status(): void
    {
        // unsupported version in header
        $this->rpc('ping', [], 1, ['MCP-Protocol-Version' => '2030-01-01'])->assertStatus(400)
            ->assertJsonPath('error.code', Protocol::UNSUPPORTED_PROTOCOL_VERSION)
            ->assertJsonPath('error.data.requested', '2030-01-01');

        // unsupported version in body
        $this->rpc('ping', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2030-01-01', 'io.modelcontextprotocol/clientCapabilities' => []]])
            ->assertStatus(400)->assertJsonPath('error.code', Protocol::UNSUPPORTED_PROTOCOL_VERSION);

        // header / body mismatch
        $this->rpc('ping', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]], 1, ['MCP-Protocol-Version' => '2025-11-25'])
            ->assertStatus(400)->assertJsonPath('error.code', Protocol::HEADER_MISMATCH);

        // Mcp-Method mismatch
        $this->modernRpc('ping', [], 1, ['Mcp-Method' => 'tools/list'])->assertStatus(400)->assertJsonPath('error.code', Protocol::HEADER_MISMATCH);

        // Mcp-Name mismatch (base64 sentinel decoded)
        $this->modernRpc('tools/call', ['name' => 'health'], 1, ['Mcp-Name' => '=?base64?'.base64_encode('logs').'?='])->assertStatus(400)->assertJsonPath('error.code', Protocol::HEADER_MISMATCH);
        $this->modernRpc('tools/call', ['name' => 'health'], 1, ['Mcp-Name' => '=?base64?'.base64_encode('health').'?='])->assertOk();

        // missing clientCapabilities
        $this->rpc('ping', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']], 1, ['MCP-Protocol-Version' => '2026-07-28'])
            ->assertStatus(400)->assertJsonPath('error.code', Protocol::INVALID_PARAMS);

        // unknown method → 404 for modern clients
        $this->modernRpc('prompts/list')->assertStatus(404)->assertJsonPath('error.code', Protocol::METHOD_NOT_FOUND);
    }

    public function test_origin_validation(): void
    {
        $this->rpc('ping', [], 1, ['Origin' => 'https://evil.example.org'])->assertStatus(403);
        $this->rpc('ping', [], 1, ['Origin' => 'https://myapp.example.com'])->assertOk();

        config()->set('cipi.mcp_allowed_origins', 'https://cursor.example.org, other.example.org');
        $this->rpc('ping', [], 1, ['Origin' => 'https://cursor.example.org'])->assertOk();
        $this->rpc('ping', [], 1, ['Origin' => 'http://other.example.org:8080'])->assertOk();
        $this->rpc('ping', [], 1, ['Origin' => 'https://evil.example.org'])->assertStatus(403);
    }

    public function test_resources_list_and_read(): void
    {
        file_put_contents(base_path('cipi.yml'), "version: 1\nschedule: true\n");
        try {
            $r = $this->rpc('resources/list')->assertOk();
            $uris = collect($r->json('result.resources'))->pluck('uri')->all();
            $this->assertContains('cipi://cipi.yml', $uris);
            $this->assertContains('cipi://cipi.yml/schema', $uris);
            $this->assertContains('cipi://anonymization.json/schema', $uris);

            $r = $this->rpc('resources/read', ['uri' => 'cipi://cipi.yml'])->assertOk();
            $this->assertSame("version: 1\nschedule: true\n", $r->json('result.contents.0.text'));
            $this->assertSame('application/yaml', $r->json('result.contents.0.mimeType'));

            $r = $this->rpc('resources/read', ['uri' => 'cipi://cipi.yml/schema'])->assertOk();
            $this->assertSame('cipi.yml', json_decode($r->json('result.contents.0.text'), true)['title']);

            $this->rpc('resources/read', ['uri' => 'cipi://nope'])->assertOk()->assertJsonPath('error.code', Protocol::INVALID_PARAMS);
        } finally {
            @unlink(base_path('cipi.yml'));
        }
    }

    private function serverHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => "Bearer {$this->mcpToken}", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }
}
