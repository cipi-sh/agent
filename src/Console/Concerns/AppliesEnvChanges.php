<?php

namespace Cipi\Agent\Console\Concerns;

/**
 * Writing to .env and making the change live.
 *
 * With a cached configuration (Cipi deploys run `artisan optimize`), Laravel
 * never reads .env again — restarting PHP-FPM or the web server changes
 * nothing. The cache is rebuilt instead; without one, the next request reads
 * the new value. Long-running processes keep the old value in memory either way.
 */
trait AppliesEnvChanges
{
    protected function writeEnv(string $key, string $value): bool
    {
        $envPath = app()->environmentFilePath();

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            return false;
        }

        $contents = (string) file_get_contents($envPath);
        $entry = "{$key}={$value}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents)) {
            $contents = (string) preg_replace($pattern, $entry, $contents, 1);
        } else {
            $contents = rtrim($contents).PHP_EOL.$entry.PHP_EOL;
        }

        return file_put_contents($envPath, $contents) !== false;
    }

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
