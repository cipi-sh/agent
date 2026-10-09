<?php

namespace Cipi\Agent\Anonymizer;

/**
 * Compares an anonymization config with the live schema.
 *
 * `audit()` reports what would break (tables or columns that do not exist,
 * unknown transformations) and what probably leaks (columns that look like
 * personal data and are not covered). `scan()` proposes a complete config
 * from the schema alone, for `cipi:init-anonymize --scan`.
 */
final class Auditor
{
    public function __construct(private readonly SchemaInspector $schema) {}

    /**
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     suggestions: array<string, array<string, string>>,
     *     truncate_suggestions: list<string>,
     *     summary: array<string, int>
     * }
     */
    public function audit(Config $config): array
    {
        $errors = [];
        $warnings = [];
        $suggestions = [];
        $truncateSuggestions = [];
        $tables = $this->schema->tables();
        $columnsCovered = 0;

        foreach ($config->transformations as $table => $columns) {
            if (! in_array($table, $tables, true)) {
                $errors[] = "transformations.{$table}: table does not exist in the database";

                continue;
            }
            $live = array_column($this->schema->columns($table), 'type', 'name');
            foreach ($columns as $column => $spec) {
                if (! array_key_exists($column, $live)) {
                    $errors[] = "transformations.{$table}.{$column}: column does not exist";

                    continue;
                }
                $columnsCovered++;
                $suggested = Heuristics::suggest($table, $column, $live[$column]);
                if ($suggested === 'password' && ! in_array($spec->type, ['password', 'null', 'fixed', 'hash'], true)) {
                    $warnings[] = "transformations.{$table}.{$column}: looks like a password column but uses '{$spec->type}' — use 'password' so the hash stays valid";
                }
                if (in_array($spec->type, ['fakeEmail', 'fakeSafeEmail', 'fakeUserName'], true) && $spec->unique === false) {
                    $warnings[] = "transformations.{$table}.{$column}: '{$spec->type}' with unique: false may violate a unique index on import";
                }
            }
        }

        foreach ($config->truncate as $table) {
            if (! in_array($table, $tables, true)) {
                $warnings[] = "truncate: table '{$table}' does not exist (ignored)";
            }
        }

        foreach ($tables as $table) {
            if ($config->truncates($table)) {
                continue;
            }
            $specs = $config->specsFor($table);
            foreach ($this->schema->columns($table) as $column) {
                if (isset($specs[$column['name']])) {
                    continue;
                }
                $suggested = Heuristics::suggest($table, $column['name'], $column['type']);
                if ($suggested !== null) {
                    $suggestions[$table][$column['name']] = $suggested;
                }
            }
            if ($specs === [] && Heuristics::isTruncateCandidate($table)) {
                $truncateSuggestions[] = $table;
            }
        }

        $uncovered = array_sum(array_map('count', $suggestions));
        if ($uncovered > 0) {
            $warnings[] = "{$uncovered} column(s) look like personal data and are not covered — see suggestions";
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'suggestions' => $suggestions,
            'truncate_suggestions' => $truncateSuggestions,
            'summary' => [
                'tables_in_database' => count($tables),
                'tables_transformed' => count($config->transformations),
                'tables_truncated' => count($config->truncate),
                'columns_transformed' => $columnsCovered,
                'columns_uncovered' => $uncovered,
            ],
        ];
    }

    /**
     * Propose a config from the schema: every column the heuristics flag,
     * plus the usual operational tables emptied.
     *
     * @return array<string, mixed>
     */
    public function scan(?string $locale = null): array
    {
        $transformations = [];
        $truncate = [];
        foreach ($this->schema->tables() as $table) {
            if (Heuristics::isTruncateCandidate($table)) {
                $truncate[] = $table;

                continue;
            }
            foreach ($this->schema->columns($table) as $column) {
                $suggested = Heuristics::suggest($table, $column['name'], $column['type']);
                if ($suggested !== null) {
                    $transformations[$table][$column['name']] = $suggested;
                }
            }
        }

        return [
            'transformations' => $transformations,
            'truncate' => $truncate,
            'options' => [
                'hash_algorithm' => 'auto',
                'password' => 'password',
                'faker_locale' => $locale ?? (string) config('app.faker_locale', 'en_US'),
                'consistent' => true,
            ],
        ];
    }
}
