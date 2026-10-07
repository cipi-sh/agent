<?php

namespace Cipi\Agent\Console\Concerns;

/**
 * After a command writes to .env: make the change live.
 *
 * With a cached configuration (Cipi deploys run `artisan optimize`), Laravel
 * never reads .env again — restarting PHP-FPM or the web server changes
 * nothing. The cache is rebuilt instead; without one, the next request reads
 * the new value. Long-running processes keep the old value in memory either way.
 */
trait AppliesEnvChanges
{
    protected function applyEnvChanges(): void
    {
        if ($this->laravel->configurationIsCached()) {
            if ($this->callSilently('config:cache') === self::SUCCESS) {
                $this->components->info('Configuration cache rebuilt — the change is live.');
            } else {
                $this->components->warn('The configuration is cached and could not be rebuilt: run php artisan config:cache (or redeploy).');
            }
        } else {
            $this->components->info('The configuration is not cached: the change applies to the next request.');
        }

        $this->line('  <fg=gray>Octane, Horizon and queue workers keep the old value until they restart (php artisan octane:reload, queue:restart — or redeploy).</>');
    }
}
