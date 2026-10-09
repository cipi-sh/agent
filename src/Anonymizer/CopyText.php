<?php

namespace Cipi\Agent\Anonymizer;

/**
 * PostgreSQL COPY text format: one row per line, tab-separated, `\N` for
 * NULL, backslash escapes for tab, newline, carriage return and backslash.
 *
 * pg_dump writes table data this way by default; it is both faster and
 * safer to transform than INSERT statements, because every field is already
 * isolated and there is no quoting to get wrong.
 */
final class CopyText
{
    /**
     * @return list<string|null>
     */
    public static function decodeRow(string $line): array
    {
        $fields = explode("\t", rtrim($line, "\n\r"));

        return array_map(fn (string $f) => $f === '\\N' ? null : self::unescape($f), $fields);
    }

    /**
     * @param  list<string|null>  $fields
     */
    public static function encodeRow(array $fields): string
    {
        return implode("\t", array_map(fn ($f) => $f === null ? '\\N' : self::escape((string) $f), $fields))."\n";
    }

    public static function escape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r', "\x08" => '\\b', "\f" => '\\f', "\v" => '\\v',
        ]);
    }

    public static function unescape(string $value): string
    {
        if (! str_contains($value, '\\')) {
            return $value;
        }

        return (string) preg_replace_callback('/\\\\(x[0-9a-fA-F]{1,2}|[0-7]{1,3}|.)/s', function (array $m): string {
            $e = $m[1];
            if ($e[0] === 'x' && strlen($e) > 1) {
                return chr((int) hexdec(substr($e, 1)));
            }
            if (ctype_digit($e[0]) && $e[0] < '8') {
                return chr((int) octdec($e));
            }

            return match ($e) {
                'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v",
                default => $e,
            };
        }, $value);
    }
}
