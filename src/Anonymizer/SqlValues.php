<?php

namespace Cipi\Agent\Anonymizer;

/**
 * Tokenizes the VALUES part of an INSERT statement and re-encodes literals.
 *
 * Dumps are not CSV: strings carry escapes (`\'` and `\\` in MySQL, `''` and
 * `E'\n'` in PostgreSQL), may contain commas and parentheses, numbers are
 * bare, and `NULL`, `_binary '…'`, `0x…`, `b'…'` and `TRUE`/`FALSE` appear
 * unquoted. The tokenizer keeps the exact text of every value so untouched
 * columns are written back byte for byte, and only the columns that are
 * transformed are re-encoded for the dump's dialect.
 */
final class SqlValues
{
    public const MYSQL = 'mysql';

    public const PGSQL = 'pgsql';

    /**
     * Split "(a, 'b'), (c, 'd')" into tuples of raw value tokens.
     *
     * Returns the tuples and the separators between them (whitespace and
     * commas) so the statement can be rebuilt without touching its layout.
     *
     * @return array{tuples: list<list<string>>, separators: list<string>, rest: string}
     */
    public static function parseTuples(string $sql, string $dialect): array
    {
        $tuples = [];
        $separators = [];
        $len = strlen($sql);
        $i = 0;
        $sep = '';

        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '(') {
                $end = self::findTupleEnd($sql, $i, $dialect);
                if ($end === null) {
                    break;
                }
                $separators[] = $sep;
                $sep = '';
                $tuples[] = self::splitValues(substr($sql, $i + 1, $end - $i - 1), $dialect);
                $i = $end + 1;

                continue;
            }
            if ($c === ',' || ctype_space($c)) {
                $sep .= $c;
                $i++;

                continue;
            }
            break;
        }

        return ['tuples' => $tuples, 'separators' => $separators, 'rest' => substr($sql, $i)];
    }

    /**
     * @param  list<list<string>>  $tuples
     * @param  list<string>  $separators
     */
    public static function buildTuples(array $tuples, array $separators, string $rest): string
    {
        $out = '';
        foreach ($tuples as $idx => $values) {
            $out .= ($separators[$idx] ?? ($idx === 0 ? '' : ','));
            $out .= '('.implode(',', $values).')';
        }

        return $out.$rest;
    }

    /**
     * Index just after the closing parenthesis of the tuple opening at $start.
     */
    private static function findTupleEnd(string $sql, int $start, string $dialect): ?int
    {
        $len = strlen($sql);
        $depth = 0;
        $i = $start;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"') {
                $i = self::skipString($sql, $i, $dialect);

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
            $i++;
        }

        return null;
    }

    /**
     * Index just after a quoted literal that starts at $i (the quote char).
     */
    private static function skipString(string $sql, int $i, string $dialect): int
    {
        $quote = $sql[$i];
        $len = strlen($sql);
        $backslashes = $dialect === self::MYSQL || ($i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e'));
        $i++;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '\\' && $backslashes) {
                $i += 2;

                continue;
            }
            if ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $i += 2; // doubled quote

                    continue;
                }

                return $i + 1;
            }
            $i++;
        }

        return $len;
    }

    /**
     * Split the inside of one tuple into raw value tokens.
     *
     * @return list<string>
     */
    public static function splitValues(string $inner, string $dialect): array
    {
        $values = [];
        $len = strlen($inner);
        $i = 0;
        $start = 0;
        $depth = 0;
        while ($i < $len) {
            $c = $inner[$i];
            if ($c === "'" || $c === '"') {
                $i = self::skipString($inner, $i, $dialect);

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $values[] = trim(substr($inner, $start, $i - $start));
                $start = $i + 1;
            }
            $i++;
        }
        $values[] = trim(substr($inner, $start));

        return $values;
    }

    /**
     * Decode a raw token into a PHP value: null for NULL, int/float for
     * numbers, string for quoted literals. Returns [kind, value]; kind is
     * 'null', 'number', 'string', 'bool' or 'raw' (something we leave alone).
     *
     * @return array{0: string, 1: mixed}
     */
    public static function decode(string $token, string $dialect): array
    {
        $t = trim($token);
        $upper = strtoupper($t);
        if ($upper === 'NULL') {
            return ['null', null];
        }
        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return ['bool', $upper === 'TRUE'];
        }
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?$/', $t)) {
            return ['number', str_contains($t, '.') || stripos($t, 'e') !== false ? (float) $t : (int) $t];
        }
        $prefix = '';
        if (preg_match('/^(_binary|_utf8mb4|_utf8|_latin1)\s+/i', $t, $m)) {
            $prefix = $m[0];
            $t = substr($t, strlen($prefix));
        }
        $escapes = $dialect === self::MYSQL;
        if ($dialect === self::PGSQL && preg_match('/^[eE]\'/', $t)) {
            $escapes = true;
            $t = substr($t, 1);
        }
        if (strlen($t) >= 2 && ($t[0] === "'" || $t[0] === '"') && $t[strlen($t) - 1] === $t[0]) {
            $quote = $t[0];
            $body = substr($t, 1, -1);

            return ['string', self::unescape($body, $quote, $escapes)];
        }

        return ['raw', $token];
    }

    /**
     * Encode a PHP value as a literal for the dump's dialect.
     */
    public static function encode(mixed $value, string $dialect): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $dialect === self::PGSQL ? ($value ? 'true' : 'false') : ($value ? '1' : '0');
        }
        if (is_int($value) || is_float($value)) {
            return is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : (string) $value;
        }
        $s = (string) $value;
        if ($dialect === self::MYSQL) {
            return "'".strtr($s, [
                '\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z',
            ])."'";
        }

        // PostgreSQL: standard_conforming_strings — only the quote is doubled.
        if (str_contains($s, '\\') || str_contains($s, "\n") || str_contains($s, "\r") || str_contains($s, "\0")) {
            $s = str_replace("\0", '', $s);

            return "E'".strtr($s, ['\\' => '\\\\', "'" => "''", "\n" => '\\n', "\r" => '\\r'])."'";
        }

        return "'".str_replace("'", "''", $s)."'";
    }

    private static function unescape(string $body, string $quote, bool $backslashes): string
    {
        $out = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '\\' && $backslashes && $i + 1 < $len) {
                $n = $body[++$i];
                $out .= match ($n) {
                    '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", 'b' => "\x08",
                    default => $n,
                };

                continue;
            }
            if ($c === $quote && $i + 1 < $len && $body[$i + 1] === $quote) {
                $out .= $quote;
                $i++;

                continue;
            }
            $out .= $c;
        }

        return $out;
    }
}
