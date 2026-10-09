<?php

namespace Cipi\Agent\Console\Commands;

use Cipi\Agent\Mcp\Protocol;
use Cipi\Agent\Panel\PanelClient;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\DeployInfo;
use Cipi\Agent\Yml\Locator;
use Cipi\Agent\Yml\Validator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CipiStatusCommand extends Command
{
    protected $signature = 'cipi:status';

    protected $description = 'Show Cipi agent status and configuration';

    public function handle(): int
    {
        $this->components->info('Cipi Agent Status  (cipi/agent '.Protocol::SERVER_VERSION.')');

        $prefix = config('cipi.route_prefix', 'cipi');
        $commit = DeployInfo::commit();
        $yml = Locator::find();

        $this->table(['Setting', 'Value'], [
            ['App User', AppHome::user() ?? '<fg=red>not set</>'],
            ['App Home', AppHome::base() ?? '—'],
            ['PHP Version', config('cipi.php_version', PHP_VERSION)],
            ['Laravel', app()->version()],
            ['Environment', app()->environment()],
            ['Webhook', config('cipi.webhook_token') ? '✓ configured' : '✗ not set'],
            ['Webhook URL', url("{$prefix}/webhook")],
            ['Deploy Branch', config('cipi.deploy_branch') ?? 'any (uses Deployer config)'],
            ['Release', DeployInfo::currentRelease() ?? '—'],
            ['Commit', $commit['commit'] ? substr($commit['commit'], 0, 12)." ({$commit['source']})" : '—'],
            ['Health Check', config('cipi.health_check') ? 'enabled — '.url("{$prefix}/health") : 'disabled'],
            ['MCP Server', config('cipi.mcp_enabled') ? 'enabled — '.url("{$prefix}/mcp").(config('cipi.mcp_token') ? '' : ' <fg=yellow>(no token)</>') : 'disabled'],
            ['Anonymizer', config('cipi.anonymizer_enabled') ? 'enabled — config: '.(AppHome::anonymizationConfig() ?? '<fg=yellow>missing</>') : 'disabled'],
            ['Panel SDK', PanelClient::configured() ? '✓ configured' : (PanelClient::installed() ? 'installed, not configured (CIPI_BASE_URL / CIPI_TOKEN)' : 'not installed (composer require cipi/sdk)')],
            ['cipi.yml', $yml ? $yml.(Validator::validateFile($yml, AppHome::user())->ok ? ' <fg=green>✓ valid</>' : ' <fg=red>✗ invalid</>') : '— (php artisan cipi:yml generate --write)'],
            ['Queue', config('queue.default', 'sync')],
            ['Cache', config('cache.default', 'file')],
            ['Session', config('session.driver', 'file')],
        ]);

        $this->newLine();
        $this->components->info('Connectivity');

        try {
            DB::connection()->getPdo();
            $this->components->twoColumnDetail('Database', '<fg=green>✓ connected</> ('.DB::connection()->getDriverName().')');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Database', '<fg=red>✗ '.$e->getMessage().'</>');
        }

        return self::SUCCESS;
    }
}
