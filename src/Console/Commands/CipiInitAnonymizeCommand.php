<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiInitAnonymizeCommand extends Command
{
    protected $signature = 'cipi:init-anonymize {--force : Overwrite if the file already exists}';

    protected $description = 'Create the anonymization.json config file at ~/.db/anonymization.json from the built-in example';

    public function handle(): int
    {
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            $this->components->error('CIPI_APP_USER is not set — cannot determine the target directory.');
            return self::FAILURE;
        }

        $targetDir  = "/home/{$appUser}/.db";
        $targetFile = "{$targetDir}/anonymization.json";
        $example    = __DIR__ . '/../../../config/anonymization.example.json';

        if (! file_exists($example)) {
            $this->components->error("Example file not found in the package: {$example}");
            return self::FAILURE;
        }

        if (file_exists($targetFile) && ! $this->option('force')) {
            $this->components->warn("File already exists: {$targetFile}");
            $this->line('  Use <fg=yellow>--force</> to overwrite it.');
            return self::FAILURE;
        }

        if (! is_dir($targetDir) && ! mkdir($targetDir, 0750, true)) {
            $this->components->error("Could not create directory: {$targetDir}");
            return self::FAILURE;
        }

        if (! copy($example, $targetFile)) {
            $this->components->error("Could not write file: {$targetFile}");
            return self::FAILURE;
        }

        chmod($targetFile, 0640);

        $this->components->info('anonymization.json created successfully');
        $this->newLine();
        $this->line("  <fg=cyan>{$targetFile}</>");
        $this->newLine();
        $this->components->warn('Edit the file to match your actual database tables and sensitive columns before using the anonymizer.');
        $this->line('  The file is outside the project repo — it will not be committed to version control.');

        return self::SUCCESS;
    }
}
