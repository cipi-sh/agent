<?php

namespace Cipi\Agent\Anonymizer;

final class Stats
{
    public int $rows = 0;

    public int $values = 0;

    /** @var array<string, int> rows transformed per table */
    public array $tables = [];

    /** @var array<string, int> rows dropped per truncated table */
    public array $truncated = [];

    /** @var list<string> */
    public array $warnings = [];

    public function row(string $table, int $values): void
    {
        $this->rows++;
        $this->values += $values;
        $this->tables[$table] = ($this->tables[$table] ?? 0) + 1;
    }

    public function dropped(string $table): void
    {
        $this->truncated[$table] = ($this->truncated[$table] ?? 0) + 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rows_transformed' => $this->rows,
            'values_transformed' => $this->values,
            'tables' => $this->tables,
            'truncated' => $this->truncated,
            'warnings' => $this->warnings,
        ];
    }
}
