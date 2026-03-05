<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiMcpCommand extends Command
{
    protected $signature = 'cipi:mcp';

    protected $description = 'Show the MCP server endpoint and setup instructions for AI assistants (Cursor, Claude Desktop)';

    public function handle(): int
    {
        $prefix  = config('cipi.route_prefix', 'cipi');
        $mcpUrl  = url("{$prefix}/mcp");
        $token   = config('cipi.webhook_token', '');
        $appUser = config('cipi.app_user', 'myapp');

        if (! config('cipi.mcp_enabled', true)) {
            $this->components->warn('MCP server is disabled. Set CIPI_MCP_ENABLED=true in your .env to enable it.');
            return self::FAILURE;
        }

        $this->components->info('Cipi MCP Server');
        $this->newLine();
        $this->line("  Endpoint : <fg=cyan>{$mcpUrl}</>");
        $this->line('  Auth     : Bearer token (CIPI_WEBHOOK_TOKEN)');
        $this->line('  Protocol : MCP 2024-11-05 over HTTP (JSON-RPC 2.0)');
        $this->newLine();

        $this->components->info('Available Tools');
        $this->table(
            ['Tool', 'Description'],
            [
                ['health',   'App, database, cache, and queue status'],
                ['app_info', 'Application configuration and Cipi details'],
                ['deploy',   'Trigger a new zero-downtime deployment'],
                ['logs',     'Read recent lines from storage/logs/laravel.log'],
                ['artisan',  'Run an Artisan command (blocking commands are restricted)'],
            ]
        );

        // ── Cursor ────────────────────────────────────────────────────────────
        $this->newLine();
        $this->components->info('Cursor Setup  (~/.cursor/mcp.json  or  Cursor → Settings → MCP)');
        $this->newLine();

        $cursorConfig = json_encode([
            'mcpServers' => [
                "cipi-{$appUser}" => [
                    'type' => 'http',
                    'url'  => $mcpUrl,
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        foreach (explode("\n", $cursorConfig) as $line) {
            $this->line("  {$line}");
        }

        // ── Claude Desktop ────────────────────────────────────────────────────
        $this->newLine();
        $this->components->info('Claude Desktop Setup  (~/Library/Application Support/Claude/claude_desktop_config.json)');
        $this->newLine();
        $this->line('  Claude Desktop requires the <fg=yellow>mcp-remote</> bridge (stdio → HTTP):');
        $this->newLine();

        $claudeConfig = json_encode([
            'mcpServers' => [
                "cipi-{$appUser}" => [
                    'command' => 'npx',
                    'args'    => [
                        '-y',
                        'mcp-remote',
                        $mcpUrl,
                        '--header',
                        "Authorization: Bearer {$token}",
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        foreach (explode("\n", $claudeConfig) as $line) {
            $this->line("  {$line}");
        }

        $this->newLine();
        $this->line('  Install mcp-remote once with: <fg=cyan>npm install -g mcp-remote</>');
        $this->newLine();

        // ── Warnings ──────────────────────────────────────────────────────────
        if (empty($token)) {
            $this->components->warn('CIPI_WEBHOOK_TOKEN is not set — the MCP endpoint will reject all requests.');
        }

        if (empty(config('cipi.app_user'))) {
            $this->components->warn('CIPI_APP_USER is not set — the deploy tool will not work.');
        }

        return self::SUCCESS;
    }
}
