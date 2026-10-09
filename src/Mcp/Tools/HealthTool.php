<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\HealthReport;

final class HealthTool extends Tool
{
    public function name(): string
    {
        return 'health';
    }

    public function title(): string
    {
        return 'Application health';
    }

    public function description(): string
    {
        return 'Check the health of this Laravel application: app (debug, maintenance mode), database, cache, queue (pending and failed jobs), writable storage and the deploy state (live release, commit, last automatic deploy). Same data as GET /cipi/health.';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function outputSchema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['healthy', 'degraded']],
                'checks' => ['type' => 'object'],
                'timestamp' => ['type' => 'string'],
            ],
            'required' => ['status', 'checks'],
        ];
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        return ToolResult::json(HealthReport::build());
    }
}
