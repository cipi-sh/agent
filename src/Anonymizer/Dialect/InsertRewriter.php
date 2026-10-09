<?php

namespace Cipi\Agent\Anonymizer\Dialect;

use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\Spec;
use Cipi\Agent\Anonymizer\SqlValues;
use Cipi\Agent\Anonymizer\Stats;
use Cipi\Agent\Anonymizer\Transformer;

/**
 * Rewrites the tuples of one INSERT statement. Shared by both dialects.
 */
final class InsertRewriter
{
    public function __construct(
        private readonly Config $config,
        private readonly Transformer $transformer,
        private readonly string $dialect,
    ) {}

    /**
     * @param  list<string>  $columns  column names in tuple order
     * @param  array<string, Spec>  $specs
     */
    public function rewrite(string $table, array $columns, array $specs, string $valuesSql, Stats $stats): string
    {
        $parsed = SqlValues::parseTuples($valuesSql, $this->dialect);
        if ($parsed['tuples'] === []) {
            $stats->warnings[] = "{$table}: could not parse an INSERT statement — left unchanged";

            return $valuesSql;
        }

        $positions = [];
        foreach ($columns as $i => $name) {
            if (isset($specs[$name])) {
                $positions[$i] = [$name, $specs[$name]];
            }
        }
        if ($positions === []) {
            return $valuesSql;
        }

        foreach ($parsed['tuples'] as &$tuple) {
            $count = 0;
            foreach ($positions as $i => [$name, $spec]) {
                if (! array_key_exists($i, $tuple)) {
                    continue;
                }
                [$kind, $value] = SqlValues::decode($tuple[$i], $this->dialect);
                if ($kind === 'raw') {
                    continue;
                }
                $new = $this->transformer->transform($table, $name, $value, $spec);
                if ($new === $value) {
                    continue;
                }
                $tuple[$i] = $this->encodeLike($kind, $new);
                $count++;
            }
            $stats->row($table, $count);
        }
        unset($tuple);

        return SqlValues::buildTuples($parsed['tuples'], $parsed['separators'], $parsed['rest']);
    }

    private function encodeLike(string $originalKind, mixed $new): string
    {
        if ($new === null) {
            return 'NULL';
        }
        if ($originalKind === 'number' && is_string($new) && is_numeric($new)) {
            return $new;
        }
        if ($originalKind === 'number' && is_bool($new)) {
            return $new ? '1' : '0';
        }

        return SqlValues::encode($new, $this->dialect);
    }
}
