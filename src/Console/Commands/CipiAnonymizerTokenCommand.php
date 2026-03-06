<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiAnonymizerTokenCommand extends Command
{
    protected $signature = 'cipi:anonymizer-token';

    protected $description = 'Generate a new database anonymizer token';

    public function handle(): int
    {
        $token = 'cipi_anonymizer_' . bin2hex(random_bytes(32));

        $this->components->info('Generated new database anonymizer token');
        $this->newLine();
        $this->line("  <fg=cyan>{$token}</>");
        $this->newLine();

        $this->components->info('Add this to your .env file:');
        $this->line("  <fg=yellow>CIPI_ANONYMIZER_TOKEN={$token}</>");
        $this->newLine();

        $this->components->warn('Keep this token secure — it provides access to database anonymization operations.');
        $this->line('  Never commit it to version control or share it publicly.');
        $this->newLine();

        $this->components->info('Once set, the following endpoints will be available:');
        $this->line('  POST /cipi/db - Start anonymization job');
        $this->line('  GET  /cipi/db/{token} - Download anonymized database dump');

        return self::SUCCESS;
    }
}