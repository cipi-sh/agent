<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiGenerateTokenCommand extends Command
{
    protected $signature = 'cipi:generate-token {type : Token type to generate (mcp, health, anonymize)}';

    protected $description = 'Generate a new secure token for a Cipi feature (mcp, health, anonymize)';

    private const TYPES = ['mcp', 'health', 'anonymize'];

    public function handle(): int
    {
        $type = strtolower($this->argument('type'));

        if (! in_array($type, self::TYPES)) {
            $this->components->error("Invalid token type \"{$type}\". Allowed values: " . implode(', ', self::TYPES));
            return self::FAILURE;
        }

        return match ($type) {
            'mcp'       => $this->generateMcpToken(),
            'health'    => $this->generateHealthToken(),
            'anonymize' => $this->generateAnonymizerToken(),
        };
    }

    private function generateMcpToken(): int
    {
        $token = 'cipi_mcp_' . bin2hex(random_bytes(32));

        $this->components->info('Generated new MCP token');
        $this->newLine();
        $this->line("  <fg=cyan>{$token}</>");
        $this->newLine();

        $this->components->info('Add this to your .env file:');
        $this->line("  <fg=yellow>CIPI_MCP_TOKEN={$token}</>");
        $this->newLine();

        $this->components->warn('Keep this token secure — it provides access to sensitive application operations.');
        $this->line('  Never commit it to version control or share it publicly.');

        return self::SUCCESS;
    }

    private function generateHealthToken(): int
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

    private function generateAnonymizerToken(): int
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
