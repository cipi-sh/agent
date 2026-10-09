<?php

namespace Cipi\Agent\Yml;

/**
 * The YAML subset Cipi reads a cipi.yml with.
 *
 * This is a line-for-line port of the parser the Cipi CLI runs on the server
 * (`cipi yml validate`), so a file that passes here passes there and the other
 * way round. The subset is deliberate: the file arrives over git, so anchors,
 * aliases, tags, merge keys, block scalars and flow mappings are refused
 * outright rather than parsed. A full YAML library would accept files the
 * server rejects.
 *
 * Mappings come back as string-keyed PHP arrays, sequences as lists.
 */
final class YamlParser
{
    public const MAX_BYTES = 65536;

    public const MAX_LINES = 2000;

    public const MAX_DEPTH = 6;

    public const MAX_SEQ = 200;

    /**
     * @return array<string, mixed>|list<mixed>
     *
     * @throws YamlError
     */
    public static function parse(string $text): array
    {
        $lines = self::tokenize($text);
        if ($lines === []) {
            return [];
        }
        if ($lines[0][0] !== 0) {
            throw new YamlError($lines[0][2], 'file must start at column 0');
        }

        [$value, $idx] = self::parseBlock($lines, 0, 0, 1);
        if ($idx !== count($lines)) {
            throw new YamlError($lines[$idx][2], 'unexpected content');
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}> [indent, content, lineno]
     */
    private static function tokenize(string $text): array
    {
        $out = [];
        $n = 0;
        foreach (explode("\n", $text) as $raw) {
            $n++;
            $raw = str_replace("\r", '', $raw);
            $leading = strlen($raw) - strlen(ltrim($raw));
            if (str_contains(substr($raw, 0, $leading), "\t")) {
                throw new YamlError($n, 'tabs cannot be used for indentation — use spaces');
            }
            $content = self::stripComment($raw);
            $stripped = trim($content);
            if ($stripped === '') {
                continue;
            }
            if ($stripped === '---' || $stripped === '...') {
                continue;
            }
            $indent = strlen($content) - strlen(ltrim($content, ' '));
            if (str_starts_with($stripped, '<<:')) {
                throw new YamlError($n, 'merge keys (<<) are not supported');
            }
            $out[] = [$indent, $stripped, $n];
        }

        return $out;
    }

    /** Remove a trailing comment, respecting quotes. */
    private static function stripComment(string $s): string
    {
        $out = '';
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                $out .= $c;
                if ($c === '\\' && $quote === '"' && $i + 1 < $len) {
                    $out .= $s[$i + 1];
                    $i++;

                    continue;
                }
                if ($c === $quote) {
                    $quote = null;
                }
            } else {
                if ($c === "'" || $c === '"') {
                    $quote = $c;
                    $out .= $c;
                } elseif ($c === '#' && ($out === '' || in_array(substr($out, -1), [' ', "\t"], true))) {
                    break;
                } else {
                    $out .= $c;
                }
            }
        }

        return rtrim($out);
    }

    private static function parseScalar(string $raw, int $lineno): mixed
    {
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        $first = $s[0];
        if ($first === '&' || $first === '*' || $first === '!') {
            throw new YamlError($lineno, 'anchors, aliases and tags are not supported');
        }
        if ($first === '|' || $first === '>') {
            throw new YamlError($lineno, 'block scalars are not supported');
        }
        if ($first === '{') {
            throw new YamlError($lineno, 'flow mappings ({...}) are not supported — use indented keys');
        }
        if ($first === '[') {
            if (! str_ends_with($s, ']')) {
                throw new YamlError($lineno, 'unterminated flow sequence');
            }
            $inner = trim(substr($s, 1, -1));
            if ($inner === '') {
                return [];
            }
            $items = [];
            foreach (self::splitFlow($inner, $lineno) as $part) {
                $items[] = self::parseScalar($part, $lineno);
            }

            return $items;
        }
        if ($first === '"') {
            if (strlen($s) < 2 || ! str_ends_with($s, '"')) {
                throw new YamlError($lineno, 'unterminated double-quoted string');
            }
            $body = substr($s, 1, -1);

            return strtr(str_replace('\\\\', "\0", $body), ['\\"' => '"', '\\n' => "\n", '\\t' => "\t", "\0" => '\\']);
        }
        if ($first === "'") {
            if (strlen($s) < 2 || ! str_ends_with($s, "'")) {
                throw new YamlError($lineno, 'unterminated single-quoted string');
            }

            return str_replace("''", "'", substr($s, 1, -1));
        }

        $low = strtolower($s);
        if (in_array($low, ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($low, ['false', 'no', 'off'], true)) {
            return false;
        }
        if ($low === 'null' || $low === '~') {
            return null;
        }
        if (preg_match('/^-?[0-9]+$/', $s)) {
            // Python ints are unbounded; anything beyond PHP's range is out of every
            // allowed range anyway, so saturate instead of silently wrapping.
            if (strlen(ltrim($s, '-')) > 18) {
                return str_starts_with($s, '-') ? PHP_INT_MIN : PHP_INT_MAX;
            }

            return (int) $s;
        }
        if (preg_match('/^-?[0-9]*\.[0-9]+$/', $s)) {
            return (float) $s;
        }
        if (str_contains($s, ': ') || str_ends_with($s, ':')) {
            throw new YamlError($lineno, "unquoted ':' in a value — wrap the value in quotes");
        }

        return $s;
    }

    /** @return list<string> */
    private static function splitFlow(string $s, int $lineno): array
    {
        $parts = [];
        $buf = '';
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
                $buf .= $c;
            } elseif ($c === ',') {
                $parts[] = $buf;
                $buf = '';
            } else {
                $buf .= $c;
            }
        }
        if ($quote !== null) {
            throw new YamlError($lineno, 'unterminated quoted string in flow sequence');
        }
        $parts[] = $buf;

        return array_values(array_filter(array_map('trim', $parts), fn (string $p) => $p !== ''));
    }

    /**
     * Split 'key: value' at the first structural colon.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitKey(string $s, int $lineno): array
    {
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === ':') {
                $rest = substr($s, $i + 1);
                if ($rest === '' || $rest[0] === ' ' || $rest[0] === "\t") {
                    return [trim(substr($s, 0, $i)), trim($rest)];
                }
            }
        }
        throw new YamlError($lineno, "expected 'key: value'");
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $lines
     * @return array{0: mixed, 1: int}
     */
    private static function parseBlock(array $lines, int $idx, int $indent, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new YamlError($lines[$idx][2], 'structure nested too deeply (max '.self::MAX_DEPTH.')');
        }
        if (str_starts_with($lines[$idx][1], '- ')) {
            return self::parseSeq($lines, $idx, $indent, $depth);
        }
        if ($lines[$idx][1] === '-') {
            throw new YamlError($lines[$idx][2], 'empty sequence item');
        }

        return self::parseMap($lines, $idx, $indent, $depth);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function parseMap(array $lines, int $idx, int $indent, int $depth): array
    {
        $out = [];
        $count = count($lines);
        while ($idx < $count) {
            [$curIndent, $content, $lineno] = $lines[$idx];
            if ($curIndent < $indent) {
                break;
            }
            if ($curIndent > $indent) {
                throw new YamlError($lineno, 'unexpected indentation');
            }
            if (str_starts_with($content, '- ')) {
                throw new YamlError($lineno, 'sequence item where a key was expected');
            }
            [$key, $value] = self::splitKey($content, $lineno);
            if ($key === '') {
                throw new YamlError($lineno, 'empty key');
            }
            if (array_key_exists($key, $out)) {
                throw new YamlError($lineno, "duplicate key '{$key}'");
            }
            if ($value === '') {
                if ($idx + 1 < $count && $lines[$idx + 1][0] > $curIndent) {
                    [$child, $idx] = self::parseBlock($lines, $idx + 1, $lines[$idx + 1][0], $depth + 1);
                    $out[$key] = $child;

                    continue;
                }
                $out[$key] = null;
                $idx++;

                continue;
            }
            $out[$key] = self::parseScalar($value, $lineno);
            $idx++;
        }

        return [$out, $idx];
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}>  $lines
     * @return array{0: list<mixed>, 1: int}
     */
    private static function parseSeq(array $lines, int $idx, int $indent, int $depth): array
    {
        $out = [];
        $count = count($lines);
        while ($idx < $count) {
            [$curIndent, $content, $lineno] = $lines[$idx];
            if ($curIndent < $indent) {
                break;
            }
            if ($curIndent > $indent) {
                throw new YamlError($lineno, 'unexpected indentation in sequence');
            }
            if (! str_starts_with($content, '- ')) {
                break;
            }
            if (count($out) >= self::MAX_SEQ) {
                throw new YamlError($lineno, 'too many list items (max '.self::MAX_SEQ.')');
            }
            $item = trim(substr($content, 2));
            $isMap = true;
            try {
                self::splitKey($item, $lineno);
            } catch (YamlError) {
                $isMap = false;
            }
            if ($isMap) {
                // A mapping opened on the dash line: re-read it as a block whose
                // indent starts just past "- ".
                $subIndent = $curIndent + 2;
                $sub = [[$subIndent, $item, $lineno]];
                $j = $idx + 1;
                while ($j < $count && $lines[$j][0] >= $subIndent
                    && ! ($lines[$j][0] === $curIndent && str_starts_with($lines[$j][1], '- '))) {
                    if ($lines[$j][0] < $subIndent) {
                        break;
                    }
                    $sub[] = $lines[$j];
                    $j++;
                }
                [$valueMap, $consumed] = self::parseMap($sub, 0, $subIndent, $depth + 1);
                if ($consumed !== count($sub)) {
                    throw new YamlError($sub[$consumed][2], 'unexpected indentation in list item');
                }
                $out[] = $valueMap;
                $idx = $j;
            } else {
                $out[] = self::parseScalar($item, $lineno);
                $idx++;
            }
        }

        return [$out, $idx];
    }
}
