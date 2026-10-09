<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Anonymizer\Heuristics;
use Cipi\Agent\Anonymizer\SchemaInspector;
use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Illuminate\Support\Facades\Schema;

final class DbSchemaTool extends Tool
{
    public function name(): string
    {
        return 'db_schema';
    }

    public function title(): string
    {
        return 'Database schema';
    }

    public function description(): string
    {
        return 'Inspect the database schema without reading data: every table with its row count, or — given a table — its columns (name, type, nullable), indexes and foreign keys, plus the columns that look like personal data. Use it before db_query and when writing the anonymization config.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'table' => ['type' => 'string', 'description' => 'Describe one table in detail. Omit to list all tables.', 'maxLength' => 128],
            'counts' => ['type' => 'boolean', 'default' => true, 'description' => 'Include row counts in the table list (COUNT(*), may be slow on very large tables).'],
        ]);
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $inspector = new SchemaInspector;
        $table = self::str($arguments, 'table');

        if ($table !== null && $table !== '') {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.$-]{0,127}$/', $table)) {
                return ToolResult::error("Invalid table name '{$table}'.");
            }
            if (! $inspector->hasTable($table)) {
                return ToolResult::error("Table '{$table}' does not exist.");
            }
            $columns = $inspector->columns($table);
            $sensitive = [];
            foreach ($columns as $c) {
                if (($s = Heuristics::suggest($table, $c['name'], $c['type'])) !== null) {
                    $sensitive[$c['name']] = $s;
                }
            }
            $indexes = [];
            $foreign = [];
            try {
                $indexes = array_map(fn ($i) => ['name' => $i['name'] ?? null, 'columns' => $i['columns'] ?? [], 'unique' => (bool) ($i['unique'] ?? false), 'primary' => (bool) ($i['primary'] ?? false)], Schema::getIndexes($table));
                $foreign = array_map(fn ($f) => ['columns' => $f['columns'] ?? [], 'references' => ($f['foreign_table'] ?? '').'('.implode(',', $f['foreign_columns'] ?? []).')', 'on_delete' => $f['on_delete'] ?? null], Schema::getForeignKeys($table));
            } catch (\Throwable) {
                // not every driver reports indexes
            }

            return ToolResult::json([
                'table' => $table,
                'rows' => $inspector->rowCount($table),
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreign,
                'looks_personal' => $sensitive,
            ]);
        }

        $counts = self::bool($arguments, 'counts', true);
        $tables = [];
        foreach ($inspector->tables() as $name) {
            $tables[] = ['table' => $name, 'rows' => $counts ? $inspector->rowCount($name) : null, 'truncate_candidate' => Heuristics::isTruncateCandidate($name)];
        }

        return ToolResult::json([
            'connection' => $inspector->connectionName(),
            'driver' => $inspector->driver(),
            'table_count' => count($tables),
            'tables' => $tables,
        ]);
    }
}
