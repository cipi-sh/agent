<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiHealthTokenCommand extends Command
{
    protected $signature = 'cipi:health-token';

    protected $description = 'Generate a new health check token';

    public function handle(): int
    {
        $token = 'cipi_health_' . bin2hex(random_bytes(32));

        $this->components->info('Generated new health check token');
        $this->newLine();
        $this->line("  <fg=cyan>{$token}</>");
        $this->newLine();

        $this->components->info('Add this to your .env file:');
        $this->line("  <fg=yellow>CIPI_HEALTH_TOKEN={$token}</>");
        $this->newLine();

        $this->components->warn('Keep this token secure — it provides access to health check information.');
        $this->line('  Never commit it to version control or share it publicly.');
        $this->newLine();

        $this->components->info('Once set, use the token to call the health check endpoint:');
        $prefix = config('cipi.route_prefix', 'cipi');
        $this->line("  GET /{$prefix}/health");
        $this->line('  Authorization: Bearer <token>');

        return self::SUCCESS;
    }
}
