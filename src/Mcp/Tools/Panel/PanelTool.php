<?php

namespace Cipi\Agent\Mcp\Tools\Panel;

use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Panel\PanelClient;

abstract class PanelTool extends Tool
{
    public function available(): bool
    {
        return PanelClient::configured();
    }

    protected function app(): string
    {
        $app = PanelClient::appName();
        if ($app === null) {
            throw new \RuntimeException('CIPI_APP_USER (or CIPI_PANEL_APP) is not configured');
        }

        return $app;
    }

    /**
     * @param  callable(object): mixed  $call
     */
    protected function call(callable $call): ToolResult
    {
        try {
            $data = $call(PanelClient::sdk());

            return is_array($data) ? ToolResult::json($data) : ToolResult::text((string) json_encode($data));
        } catch (\Throwable $e) {
            return ToolResult::error(PanelClient::describe($e));
        }
    }
}
