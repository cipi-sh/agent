<?php

namespace Cipi\Agent\Console\Commands;

use Cipi\Agent\Anonymizer\Auditor;
use Cipi\Agent\Anonymizer\SchemaInspector;
use Cipi\Agent\Support\AppHome;
use Illuminate\Console\Command;

class CipiInitAnonymizeCommand extends Command
{
    protected $signature = 'cipi:init-anonymize
        {--scan : Build the config from the live database schema (personal-data heuristics) instead of the example}
        {--path= : Write to this path (default: ~/.db/anonymization.json)}
        {--stdout : Print the config instead of writing it}
        {--force : Overwrite if the file already exists}';

    protected $description = 'Create anonymization.json at ~/.db/anonymization.json — from the built-in example, or scanned from the live database schema';

    public function handle(): int
    {
        $json = $this->option('scan') ? $this->scanned() : $this->example();
        if ($json === null) {
            return self::FAILURE;
        }

        if ($this->option('stdout')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $target = $this->option('path');
        if (! $target) {
            $home = AppHome::base();
            if ($home === null) {
                $this->components->error('CIPI_APP_USER is not set — cannot determine the target directory (use --path=… or --stdout).');

                return self::FAILURE;
            }
            $target = "{$home}/.db/anonymization.json";
        }

        if (file_exists($target) && ! $this->option('force')) {
            $this->components->warn("File already exists: {$target}");
            $this->line('  Use <fg=yellow>--force</> to overwrite it.');

            return self::FAILURE;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true)) {
            $this->components->error("Could not create directory: {$dir}");

            return self::FAILURE;
        }
        if (file_put_contents($target, $json) === false) {
            $this->components->error("Could not write file: {$target}");

            return self::FAILURE;
        }
        @chmod($target, 0640);

        $this->components->info('anonymization.json created');
        $this->newLine();
        $this->line("  <fg=cyan>{$target}</>");
        $this->newLine();
        if ($this->option('scan')) {
            $this->components->warn('The config was proposed from column names — review every table before the first dump.');
        } else {
            $this->components->warn('Edit the file to match your actual tables and sensitive columns before using the anonymizer.');
        }
        $this->line('  Check it against the database:  <fg=cyan>php artisan cipi:anonymize-audit</>');
        $this->line('  The file is outside the project repo — it will not be committed to version control.');

        return self::SUCCESS;
    }

    private function example(): ?string
    {
        $example = dirname(__DIR__, 3).'/config/anonymization.example.json';
        if (! is_file($example)) {
            $this->components->error("Example file not found in the package: {$example}");

            return null;
        }

        return (string) file_get_contents($example);
    }

    private function scanned(): ?string
    {
        try {
            $proposal = (new Auditor(new SchemaInspector))->scan();
        } catch (\Throwable $e) {
            $this->components->error('Could not read the database schema: '.$e->getMessage());

            return null;
        }
        if ($proposal['transformations'] === [] && $proposal['truncate'] === []) {
            $this->components->warn('No column looked like personal data and no operational table was found — starting from the example instead.');

            return $this->example();
        }
        $columns = array_sum(array_map('count', $proposal['transformations']));
        $this->components->info(count($proposal['transformations']).' table(s), '.$columns.' column(s) proposed; '.count($proposal['truncate']).' table(s) to truncate');

        return (string) json_encode(['$schema' => 'https://cipi.sh/schema/anonymization.schema.json'] + $proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
