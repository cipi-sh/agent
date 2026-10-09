<?php

namespace Cipi\Agent\Mcp\Tools\Panel;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\ToolResult;

final class PanelDbBackupTool extends PanelTool
{
    public function name(): string
    {
        return 'panel_db_backup';
    }

    public function title(): string
    {
        return 'Panel: database backup';
    }

    public function description(): string
    {
        return 'Take a server-side snapshot of this app\'s database through the Cipi panel API (`cipi db backup`, gzipped under /var/log/cipi/backups). Useful right before a risky migration or data fix. Returns the panel job; pass wait=true to block until it completes. Needs a token with dbs-manage.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'wait' => ['type' => 'boolean', 'default' => false],
            'engine' => ['type' => 'string', 'enum' => ['mariadb', 'pgsql'], 'description' => 'Only when the database engine differs from the server default.'],
        ]);
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $wait = self::bool($arguments, 'wait', false);
        $engine = self::str($arguments, 'engine');

        return $this->call(function (object $cipi) use ($wait, $engine): array {
            $job = $cipi->databases()->backup($this->app(), $engine ?: null);

            return $wait ? $cipi->wait($job, 600) : $job;
        });
    }
}
