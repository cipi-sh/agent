<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CipiStatusCommand extends Command
{
    protected $signature = 'cipi:status';

    protected $description = 'Show Cipi agent status and configuration';

    public function handle(): int
    {
        $this->components->info('Cipi Agent Status');

        $appUser = config('cipi.app_user', 'not set');
        $phpVersion = config('cipi.php_version', PHP_VERSION);
        $webhookConfigured = ! empty(config('cipi.webhook_token'));
        $routePrefix = config('cipi.route_prefix', 'cipi');

        $this->table(['Setting', 'Value'], [
            ['App User', $appUser],
            ['PHP Version', $phpVersion],
            ['Laravel', app()->version()],
            ['Environment', app()->environment()],
            ['Webhook', $webhookConfigured ? '✓ configured' : '✗ not set'],
            ['Webhook URL', url("{$routePrefix}/webhook")],
            ['Health URL', url("{$routePrefix}/health")],
            ['Health Check', config('cipi.health_check') ? 'enabled' : 'disabled'],
            ['Deploy Branch', config('cipi.deploy_branch') ?? 'any (uses Deployer config)'],
            ['Queue', config('queue.default', 'sync')],
            ['Cache', config('cache.default', 'file')],
            ['Session', config('session.driver', 'file')],
        ]);

        // Quick connectivity checks
        $this->newLine();
        $this->components->info('Connectivity');

        try {
            DB::connection()->getPdo();
            $this->components->twoColumnDetail('Database', '<fg=green>✓ connected</>');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('Database', '<fg=red>✗ ' . $e->getMessage() . '</>');
        }

        return self::SUCCESS;
    }
}
