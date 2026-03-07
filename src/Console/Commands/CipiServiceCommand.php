<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiServiceCommand extends Command
{
    protected $signature = 'cipi:service
                            {type : Service to manage (mcp, health, anonymize)}
                            {--enable : Enable the service}
                            {--disable : Disable the service}';

    protected $description = 'Enable or disable a Cipi service by updating the .env file (mcp, health, anonymize)';

    private const SERVICES = [
        'mcp'       => 'CIPI_MCP',
        'health'    => 'CIPI_HEALTH_CHECK',
        'anonymize' => 'CIPI_ANONYMIZER',
    ];

    public function handle(): int
    {
        $type = strtolower($this->argument('type'));

        if (! array_key_exists($type, self::SERVICES)) {
            $this->components->error("Invalid service \"{$type}\". Allowed values: " . implode(', ', array_keys(self::SERVICES)));
            return self::FAILURE;
        }

        $enable  = $this->option('enable');
        $disable = $this->option('disable');

        if ($enable && $disable) {
            $this->components->error('Cannot use --enable and --disable at the same time.');
            return self::FAILURE;
        }

        if (! $enable && ! $disable) {
            $this->components->error('You must specify either --enable or --disable.');
            return self::FAILURE;
        }

        $envKey = self::SERVICES[$type];
        $value  = $enable ? 'true' : 'false';

        if (! $this->writeEnv($envKey, $value)) {
            $this->components->error("Could not write to .env file. Make sure it exists and is writable.");
            return self::FAILURE;
        }

        $action = $enable ? 'enabled' : 'disabled';
        $color  = $enable ? 'green' : 'yellow';

        $this->components->info("Service <fg={$color}>{$type}</> has been {$action}.");
        $this->newLine();
        $this->line("  <fg=cyan>{$envKey}</>=<fg={$color}>{$value}</>");
        $this->newLine();
        $this->components->warn('Remember to restart your PHP-FPM or web server for the change to take effect.');

        return self::SUCCESS;
    }

    private function writeEnv(string $key, string $value): bool
    {
        $envPath = app()->environmentFilePath();

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            return false;
        }

        $contents = file_get_contents($envPath);
        $entry    = "{$key}={$value}";
        $pattern  = "/^{$key}=.*/m";

        if (preg_match($pattern, $contents)) {
            $contents = preg_replace($pattern, $entry, $contents);
        } else {
            $contents = rtrim($contents) . PHP_EOL . $entry . PHP_EOL;
        }

        file_put_contents($envPath, $contents);

        return true;
    }
}
