<?php

namespace Cipi\Agent\Console\Commands;

use Cipi\Agent\Mcp\Protocol;
use Cipi\Agent\Mcp\Registry;
use Cipi\Agent\Mcp\Resource;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Panel\PanelClient;
use Cipi\Agent\Support\AppHome;
use Illuminate\Console\Command;

class CipiMcpCommand extends Command
{
    protected $signature = 'cipi:mcp {--json : Print only the client configuration (Cursor / VS Code format)}';

    protected $description = 'Show the MCP server endpoint, its tools and ready-to-paste client configuration (Cursor, VS Code, Claude Code, Claude Desktop)';

    public function handle(Registry $registry): int
    {
        $prefix = config('cipi.route_prefix', 'cipi');
        $url = url("{$prefix}/mcp");
        $token = (string) config('cipi.mcp_token', '');
        $app = AppHome::user() ?? 'myapp';
        $name = "cipi-{$app}";

        if (! config('cipi.mcp_enabled', false)) {
            $this->components->warn('MCP server is disabled. Enable it with: php artisan cipi:service mcp --enable');

            return self::FAILURE;
        }

        $httpConfig = ['mcpServers' => [$name => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => "Bearer {$token}"]]]];

        if ($this->option('json')) {
            $this->line((string) json_encode($httpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Cipi MCP Server');
        $this->components->twoColumnDetail('Endpoint', "<fg=cyan>{$url}</>");
        $this->components->twoColumnDetail('Transport', 'Streamable HTTP (POST, JSON-RPC 2.0)');
        $this->components->twoColumnDetail('Protocol', 'MCP '.implode(', ', Protocol::supported()));
        $this->components->twoColumnDetail('Auth', 'Bearer token (CIPI_MCP_TOKEN)');
        $this->components->twoColumnDetail('Server', Protocol::SERVER_NAME.' '.Protocol::SERVER_VERSION);
        $this->newLine();

        $this->components->info('Tools');
        $this->table(['Tool', 'What it does', 'Mode'], array_map(fn (Tool $t) => [
            $t->name(),
            $t->title(),
            ($t->annotations()['readOnlyHint'] ?? false) ? 'read-only' : (($t->annotations()['destructiveHint'] ?? true) ? 'can change data' : 'action'),
        ], $registry->tools()));

        $this->components->info('Resources');
        $this->table(['URI', 'Description'], array_map(fn (Resource $r) => [$r->uri(), $r->title()], $registry->resources()));

        if (! PanelClient::configured()) {
            $this->line('  <fg=gray>Panel tools (rollback, deploy audit, DB backup) appear when cipi/sdk is installed and CIPI_BASE_URL + CIPI_TOKEN are set.</>');
            $this->newLine();
        }

        $this->components->info('Cursor  (~/.cursor/mcp.json  or  Cursor → Settings → MCP)');
        $this->printJson($httpConfig);

        $this->components->info('VS Code  (.vscode/mcp.json — the token is asked once and stored securely)');
        $this->printJson([
            'inputs' => [['type' => 'promptString', 'id' => 'cipi-token', 'description' => "Cipi MCP token for {$app}", 'password' => true]],
            'servers' => [$name => ['type' => 'http', 'url' => $url, 'headers' => ['Authorization' => 'Bearer ${input:cipi-token}']]],
        ]);

        $this->components->info('Claude Code');
        $this->line("  <fg=cyan>claude mcp add --transport http {$name} {$url} --header \"Authorization: Bearer {$token}\"</>");
        $this->newLine();

        $this->components->info('Claude Desktop  (~/Library/Application Support/Claude/claude_desktop_config.json — via the mcp-remote bridge)');
        $this->printJson(['mcpServers' => [$name => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $url, '--header', "Authorization: Bearer {$token}"]]]]);
        $this->line('  <fg=gray>Install mcp-remote once with: npm install -g mcp-remote</>');
        $this->newLine();

        if ($token === '') {
            $this->components->warn('CIPI_MCP_TOKEN is not set — the endpoint rejects every request. Generate one: php artisan cipi:generate-token mcp');
        }
        if (AppHome::user() === null) {
            $this->components->warn('CIPI_APP_USER is not set — deploy, deploy_status and infrastructure logs need it.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function printJson(array $data): void
    {
        foreach (explode("\n", (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
            $this->line("  {$line}");
        }
        $this->newLine();
    }
}
