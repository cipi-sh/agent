<?php

namespace Cipi\Agent\Mcp\Tools\Panel;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\ToolResult;

final class PanelStatusTool extends PanelTool
{
    public function name(): string
    {
        return 'panel_status';
    }

    public function title(): string
    {
        return 'Panel: app and server status';
    }

    public function description(): string
    {
        return 'Through the Cipi panel API (cipi/sdk): this app as the panel sees it (domain, PHP, repository, branch, environment label, workers, Horizon, Reverb, aliases, redirects, limits) and the server snapshot (CPU, RAM, disk, services). Needs a token with apps-view and status-view.';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        return $this->call(function (object $cipi): array {
            $out = ['app' => $cipi->apps()->get($this->app())];
            try {
                $out['server'] = $cipi->server()->status();
            } catch (\Throwable $e) {
                $out['server'] = ['error' => $e->getMessage()];
            }

            return $out;
        });
    }
}
