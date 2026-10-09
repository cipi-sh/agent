<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\DeployTrigger;

final class DeployTool extends Tool
{
    public function name(): string
    {
        return 'deploy';
    }

    public function title(): string
    {
        return 'Queue a deploy';
    }

    public function description(): string
    {
        return 'Queue a zero-downtime deployment of this application. Writes the deploy trigger the Cipi cron picks up within one minute to run Deployer (clone, composer install, migrate, symlink swap, worker restart, post-deploy healthcheck). Who asked is recorded in the deploy audit ledger as a claim. Refuses while another deploy is running; use deploy_status to follow progress and panel_rollback (if the panel SDK is configured) to undo.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'actor' => ['type' => 'string', 'description' => 'Who is asking for this deploy (e-mail or name), recorded in the audit ledger.', 'maxLength' => 128],
            'ref' => ['type' => 'string', 'description' => 'Branch or ref being deployed, informational (Deployer deploys the configured branch).', 'maxLength' => 128],
            'request_id' => ['type' => 'string', 'description' => 'Correlation id for the audit ledger (ticket, chat id, …).', 'maxLength' => 64],
        ]);
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        if (DeployTrigger::inProgress()) {
            return ToolResult::error('A deploy is already running. Wait for it to finish (deploy_status shows the last deploy and the log), then queue again.', [
                'success' => false, 'in_progress' => true,
            ]);
        }
        if (DeployTrigger::pending()) {
            return ToolResult::json([
                'success' => true,
                'already_queued' => true,
                'message' => 'A deploy is already queued and will start within 1 minute.',
            ]);
        }

        $result = DeployTrigger::queue('mcp', [
            'actor' => self::str($arguments, 'actor') ?: ($context->clientName() !== null ? 'mcp:'.$context->clientName() : null),
            'ip' => $context->ip(),
            'ref' => self::str($arguments, 'ref'),
            'request_id' => self::str($arguments, 'request_id'),
            'branch' => self::str($arguments, 'ref') ?: config('cipi.deploy_branch'),
        ]);

        return $result['success'] ? ToolResult::json($result) : ToolResult::error($result['error'] ?? 'Could not queue the deploy', $result);
    }
}
