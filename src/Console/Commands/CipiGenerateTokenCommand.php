<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiGenerateTokenCommand extends Command
{
    protected $signature = 'cipi:generate-token {type : Token type to generate (mcp, health, anonymize)}';

    protected $description = 'Generate a new secure token for a Cipi feature and write it to .env (mcp, health, anonymize)';

    private const TOKENS = [
        'mcp'       => ['prefix' => 'cipi_mcp_',        'env_key' => 'CIPI_MCP_TOKEN'],
        'health'    => ['prefix' => 'cipi_health_',     'env_key' => 'CIPI_HEALTH_TOKEN'],
        'anonymize' => ['prefix' => 'cipi_anonymizer_', 'env_key' => 'CIPI_ANONYMIZER_TOKEN'],
    ];

    public function handle(): int
    {
        $type = strtolower($this->argument('type'));

        if (! array_key_exists($type, self::TOKENS)) {
            $this->components->error("Invalid token type \"{$type}\". Allowed values: " . implode(', ', array_keys(self::TOKENS)));
            return self::FAILURE;
        }

        $config  = self::TOKENS[$type];
        $token   = $config['prefix'] . bin2hex(random_bytes(32));
        $envKey  = $config['env_key'];

        if (! $this->writeEnv($envKey, $token)) {
            $this->components->error('Could not write to .env file. Make sure it exists and is writable.');
            $this->newLine();
            $this->line("  Add manually: <fg=yellow>{$envKey}={$token}</>");
            return self::FAILURE;
        }

        $this->components->info("Generated new {$type} token and saved to .env");
        $this->newLine();
        $this->line("  <fg=cyan>{$token}</>");
        $this->newLine();
        $this->line("  <fg=green>{$envKey}</> updated in <fg=green>.env</>");
        $this->newLine();
        $this->components->warn('Keep this token secure — never commit it to version control or share it publicly.');

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
