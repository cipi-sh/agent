<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\DeployInfo;
use Cipi\Agent\Support\DeployTrigger;
use Cipi\Agent\Support\Environment;
use Cipi\Agent\Support\Redactor;
use Cipi\Agent\Yml\Locator;

final class DeployStatusTool extends Tool
{
    public function name(): string
    {
        return 'deploy_status';
    }

    public function title(): string
    {
        return 'Deploy status';
    }

    public function description(): string
    {
        return 'What is deployed and what is happening: live release and commit, the deploy branch and the Cipi environment label, the releases kept for rollback, whether a deploy is queued or running, the outcome of the last automatic deploy (trigger, branch, duration, exit code) and optionally the tail of the deploy log.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'log_lines' => ['type' => 'integer', 'description' => 'Lines of ~/logs/deploy.log to include (0 = none). Max 300.', 'default' => 0, 'minimum' => 0, 'maximum' => 300],
            'releases' => ['type' => 'integer', 'description' => 'How many releases to list. Default 10, max 50.', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
        ]);
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $logLines = self::int($arguments, 'log_lines', 0, 0, 300);
        $commit = DeployInfo::commit();

        $data = [
            'app' => AppHome::user(),
            'release' => DeployInfo::currentRelease(),
            'commit' => $commit['commit'],
            'commit_source' => $commit['source'],
            'deploy_branch' => config('cipi.deploy_branch') ?: 'any (Deployer config)',
            'env' => Environment::label(),
            'pending' => DeployTrigger::pending(),
            'in_progress' => DeployTrigger::inProgress(),
            'last_deploy' => DeployInfo::lastDeploy(),
            'releases' => DeployInfo::releases(self::int($arguments, 'releases', 10, 1, 50)),
            'cipi_yml' => Locator::find(),
        ];

        if ($logLines > 0 && ($log = AppHome::deployLog()) !== null && is_file($log)) {
            $data['deploy_log'] = Redactor::logs(implode('', DeployInfo::tail($log, $logLines)));
        }

        return ToolResult::json($data);
    }
}
