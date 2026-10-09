<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Panel\PanelClient;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\DeployInfo;
use Cipi\Agent\Yml\Locator;

final class AppInfoTool extends Tool
{
    public function name(): string
    {
        return 'app_info';
    }

    public function title(): string
    {
        return 'Application info';
    }

    public function description(): string
    {
        return 'Configuration overview of this application: app user, PHP and Laravel versions, environment, drivers (queue, cache, session, database), Cipi endpoints, enabled agent features, the live release and commit, and whether a cipi.yml is present. Secrets are never included.';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $prefix = config('cipi.route_prefix', 'cipi');
        $commit = DeployInfo::commit();
        $default = (string) config('database.default');

        return ToolResult::json([
            'app_user' => AppHome::user(),
            'app_name' => config('app.name'),
            'app_url' => config('app.url'),
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'php_cipi' => config('cipi.php_version', PHP_VERSION),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'drivers' => [
                'queue' => config('queue.default', 'sync'),
                'cache' => config('cache.default', 'file'),
                'session' => config('session.driver', 'file'),
                'database' => config("database.connections.{$default}.driver"),
                'database_name' => config("database.connections.{$default}.database"),
                'mail' => config('mail.default'),
                'broadcast' => config('broadcasting.default'),
            ],
            'cipi' => [
                'home' => AppHome::base(),
                'deploy_branch' => config('cipi.deploy_branch') ?: 'any',
                'webhook_url' => url("{$prefix}/webhook"),
                'health_url' => url("{$prefix}/health"),
                'mcp_url' => url("{$prefix}/mcp"),
                'health_check_enabled' => (bool) config('cipi.health_check', true),
                'anonymizer_enabled' => (bool) config('cipi.anonymizer_enabled', false),
                'anonymizer_config' => AppHome::anonymizationConfig(),
                'panel_sdk' => PanelClient::configured() ? 'configured' : (PanelClient::installed() ? 'installed, not configured' : 'not installed'),
                'cipi_yml' => Locator::find(),
            ],
            'deploy' => [
                'release' => DeployInfo::currentRelease(),
                'commit' => $commit['commit'],
                'commit_source' => $commit['source'],
            ],
            'agent_version' => \Cipi\Agent\Mcp\Protocol::SERVER_VERSION,
        ]);
    }
}
