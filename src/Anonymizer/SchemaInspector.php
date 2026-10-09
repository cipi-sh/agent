<?php

namespace Cipi\Agent\Anonymizer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables and columns of the live database, for the audit, the scanner and
 * the MCP `db_schema` tool.
 */
final class SchemaInspector
{
    public function __construct(private readonly ?string $connection = null) {}

    public function connectionName(): string
    {
        return $this->connection ?? (string) config('database.default');
    }

    public function driver(): string
    {
        return DB::connection($this->connection)->getDriverName();
    }

    /** @return list<string> */
    public function tables(): array
    {
        $names = [];
        foreach (Schema::connection($this->connection)->getTables() as $table) {
            $name = is_array($table) ? ($table['name'] ?? null) : (is_object($table) ? ($table->name ?? null) : null);
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    public function hasTable(string $table): bool
    {
        return Schema::connection($this->connection)->hasTable($table);
    }

    /** @return list<array{name: string, type: string, nullable: bool}> */
    public function columns(string $table): array
    {
        $out = [];
        foreach (Schema::connection($this->connection)->getColumns($table) as $column) {
            $c = is_array($column) ? $column : (array) $column;
            $out[] = [
                'name' => (string) ($c['name'] ?? ''),
                'type' => (string) ($c['type_name'] ?? $c['type'] ?? ''),
                'nullable' => (bool) ($c['nullable'] ?? false),
            ];
        }

        return $out;
    }

    public function rowCount(string $table): ?int
    {
        try {
            return (int) DB::connection($this->connection)->table($table)->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
