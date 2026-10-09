<?php

namespace Cipi\Agent\Mcp\Tools\Panel;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\ToolResult;

final class PanelDeployAuditTool extends PanelTool
{
    public function name(): string
    {
        return 'panel_deploy_audit';
    }

    public function title(): string
    {
        return 'Panel: deploy audit ledger';
    }

    public function description(): string
    {
        return 'The hash-chained deploy audit ledger of this app from the Cipi panel API: every published release, failed deploy and rollback with release, commit, origin (cli, panel, webhook, mcp, ssh, cron), operator, IP and the claims the agent attached. Needs a token with deploy-manage.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30, 'description' => 'How far back to look.'],
        ]);
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $days = self::int($arguments, 'days', 30, 1, 365);

        return $this->call(fn (object $cipi) => $cipi->deploys()->audit($this->app(), $days));
    }
}
