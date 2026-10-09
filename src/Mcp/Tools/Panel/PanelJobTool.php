<?php

namespace Cipi\Agent\Mcp\Tools\Panel;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\ToolResult;

final class PanelJobTool extends PanelTool
{
    public function name(): string
    {
        return 'panel_job';
    }

    public function title(): string
    {
        return 'Panel: job status';
    }

    public function description(): string
    {
        return 'Status, structured result and CLI output of an asynchronous Cipi panel job (returned by panel_rollback, panel_db_backup and other 202 responses).';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'job_id' => ['type' => 'string', 'maxLength' => 128],
        ], ['job_id']);
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $id = self::str($arguments, 'job_id', '') ?? '';
        if ($id === '') {
            return ToolResult::error('job_id is required.');
        }

        return $this->call(fn (object $cipi) => $cipi->jobs()->get($id));
    }
}
