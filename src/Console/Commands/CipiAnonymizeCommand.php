<?php

namespace Cipi\Agent\Console\Commands;

use Cipi\Agent\Anonymizer\Anonymizer;
use Cipi\Agent\Anonymizer\Auditor;
use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\ConfigException;
use Cipi\Agent\Anonymizer\SchemaInspector;
use Cipi\Agent\Anonymizer\Transformer;
use Cipi\Agent\Support\AppHome;
use Illuminate\Console\Command;

class CipiAnonymizeCommand extends Command
{
    protected $signature = 'cipi:anonymize
        {config? : Path to anonymization.json (default: the server config in ~/.db)}
        {output? : Output file (default: storage/cipi/anonymized_<timestamp>.sql)}
        {--temp-dir= : Temporary directory for processing}
        {--gzip : Compress the output (.sql.gz)}
        {--check : Validate the config and audit it against the live schema without dumping}
        {--transformations : List the available transformations and exit}';

    protected $description = 'Create an anonymized database dump (MySQL/MariaDB or PostgreSQL) from the anonymization config';

    public function handle(): int
    {
        if ($this->option('transformations')) {
            $this->components->info('Available transformations');
            $this->table(['Transformation', 'Description'], array_map(fn ($k, $v) => [$k, $v], array_keys(Transformer::TYPES), Transformer::TYPES));
            $this->line('  <fg=gray>Object form: {"type": "fakeEmail", "unique": true, "consistent": true}. Argument form: fixed:ACME, numerify:###, mask:1,2, hash:32</>');

            return self::SUCCESS;
        }

        $configPath = $this->argument('config') ?: AppHome::anonymizationConfig();
        if ($configPath === null) {
            $this->components->error('No anonymization config found. Looked in: '.implode(', ', AppHome::anonymizationConfigCandidates()));
            $this->line('  Create one from the live schema: <fg=cyan>php artisan cipi:init-anonymize --scan</>');

            return self::FAILURE;
        }

        try {
            $config = Config::load($configPath);
        } catch (ConfigException $e) {
            $this->components->error("Invalid anonymization config ({$configPath}):");
            foreach ($e->errors as $error) {
                $this->line("    <fg=red>✗</> {$error}");
            }

            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->call('cipi:anonymize-audit', ['config' => $configPath]);
        }

        $output = $this->argument('output') ?: storage_path('cipi/anonymized_'.date('Ymd_His').'.sql');
        $gzip = $this->option('gzip') ? true : null;

        $this->components->info("Anonymizing database using {$configPath}");

        try {
            $report = (new Anonymizer($config))->run($output, $this->option('temp-dir') ?: null, $gzip, fn (string $m) => $this->components->task($m, fn () => true));
        } catch (\Throwable $e) {
            $this->components->error('Anonymization failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Database anonymization completed');
        $this->components->twoColumnDetail('Output', "<fg=green>{$report['output']}</>");
        $this->components->twoColumnDetail('Size', $report['size'].($report['gzip'] ? ' (gzip)' : ''));
        $this->components->twoColumnDetail('Dialect', $report['dialect']);
        $this->components->twoColumnDetail('Rows transformed', (string) $report['rows_transformed']);
        foreach ($report['tables'] as $table => $rows) {
            $this->components->twoColumnDetail("  {$table}", "{$rows} rows");
        }
        foreach ($report['truncated'] as $table => $rows) {
            $this->components->twoColumnDetail("  {$table}", "<fg=yellow>{$rows} rows dropped</>");
        }
        $this->components->twoColumnDetail('Duration', $report['duration_seconds'].'s');
        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}
