<?php

namespace Cipi\Agent\Console\Commands;

use Cipi\Agent\Anonymizer\Auditor;
use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\ConfigException;
use Cipi\Agent\Anonymizer\SchemaInspector;
use Cipi\Agent\Support\AppHome;
use Illuminate\Console\Command;

class CipiAnonymizeAuditCommand extends Command
{
    protected $signature = 'cipi:anonymize-audit
        {config? : Path to anonymization.json (default: the server config in ~/.db)}
        {--json : Machine-readable report}';

    protected $description = 'Check the anonymization config against the live database: missing tables/columns, unknown transformations, and personal-data columns left uncovered';

    public function handle(): int
    {
        $configPath = $this->argument('config') ?: AppHome::anonymizationConfig();
        if ($configPath === null) {
            $this->components->error('No anonymization config found. Looked in: '.implode(', ', AppHome::anonymizationConfigCandidates()));

            return self::FAILURE;
        }

        try {
            $config = Config::load($configPath);
        } catch (ConfigException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['ok' => false, 'file' => $configPath, 'errors' => $e->errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error("Invalid anonymization config ({$configPath}):");
                foreach ($e->errors as $error) {
                    $this->line("    <fg=red>✗</> {$error}");
                }
            }

            return self::FAILURE;
        }

        $report = (new Auditor(new SchemaInspector($config->options['connection'] ?? null)))->audit($config);

        if ($this->option('json')) {
            $this->line((string) json_encode(['file' => $configPath] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info("Anonymization audit — {$configPath}");
        $s = $report['summary'];
        $this->components->twoColumnDetail('Tables in database', (string) $s['tables_in_database']);
        $this->components->twoColumnDetail('Tables transformed', (string) $s['tables_transformed']);
        $this->components->twoColumnDetail('Tables truncated', (string) $s['tables_truncated']);
        $this->components->twoColumnDetail('Columns transformed', (string) $s['columns_transformed']);
        $this->components->twoColumnDetail('Columns that look personal but are uncovered', $s['columns_uncovered'] > 0 ? "<fg=yellow>{$s['columns_uncovered']}</>" : '<fg=green>0</>');
        $this->newLine();

        foreach ($report['errors'] as $error) {
            $this->line("  <fg=red>✗</> {$error}");
        }
        foreach ($report['warnings'] as $warning) {
            $this->line("  <fg=yellow>!</> {$warning}");
        }

        if ($report['suggestions'] !== []) {
            $this->newLine();
            $this->components->info('Suggested additions (review before adopting)');
            $rows = [];
            foreach ($report['suggestions'] as $table => $columns) {
                foreach ($columns as $column => $transformation) {
                    $rows[] = [$table, $column, $transformation];
                }
            }
            $this->table(['Table', 'Column', 'Transformation'], $rows);
        }
        if ($report['truncate_suggestions'] !== []) {
            $this->components->info('Tables you may want to truncate in the dump: '.implode(', ', $report['truncate_suggestions']));
        }

        $this->newLine();
        if ($report['ok']) {
            $this->components->info('The config is consistent with the database schema.');
        } else {
            $this->components->error('The config references tables or columns that do not exist — fix it before running the anonymizer.');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
