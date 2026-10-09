<?php

namespace Cipi\Agent\Mcp\Tools\Panel;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\ToolResult;

final class PanelRollbackTool extends PanelTool
{
    public function name(): string
    {
        return 'panel_rollback';
    }

    public function title(): string
    {
        return 'Panel: roll back the last deploy';
    }

    public function description(): string
    {
        return 'Roll this app back to the previous release through the Cipi panel API (`cipi deploy --rollback`): the code symlink is switched back and workers restart; database migrations are NOT undone. Returns the panel job; pass wait=true to block until it completes. Needs a token with deploy-manage.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'wait' => ['type' => 'boolean', 'default' => false, 'description' => 'Wait for the job to finish (up to 5 minutes).'],
        ]);
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $wait = self::bool($arguments, 'wait', false);

        return $this->call(function (object $cipi) use ($wait): array {
            $job = $cipi->deploys()->rollback($this->app());

            return $wait ? $cipi->wait($job, 300) : $job;
        });
    }
}
