<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Illuminate\Support\Facades\DB;

final class DbQueryTool extends Tool
{
    public const BLOCKED_PATTERNS = [
        '/\bDROP\s+(DATABASE|SCHEMA|TABLE|VIEW|INDEX|TRIGGER|FUNCTION|PROCEDURE|USER|ROLE|SEQUENCE|TYPE)\b/i',
        '/\bCREATE\s+(DATABASE|SCHEMA|USER|ROLE|TABLESPACE|EVENT|TRIGGER|FUNCTION|PROCEDURE)\b/i',
        '/\bALTER\s+(DATABASE|SCHEMA|TABLE|USER|ROLE|SYSTEM|SERVER)\b/i',
        '/\bRENAME\s+TABLE\b/i',
        '/\bTRUNCATE\b/i',
        '/\bLOAD\s+(DATA|XML)\b/i',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i',
        '/\bGRANT\s/i',
        '/\bREVOKE\s/i',
        '/\bSET\s+(GLOBAL|PERSIST|PERSIST_ONLY)\b/i',
        '/\b(SHUTDOWN|KILL)\b/i',
        '/\bCOPY\s+.*\b(FROM|TO)\s+(PROGRAM|\'|")/i',
        '/\bpg_(read|write)_(binary_)?file\b/i',
        '/\blo_(import|export)\b/i',
    ];

    public function name(): string
    {
        return 'db_query';
    }

    public function title(): string
    {
        return 'Run SQL query';
    }

    public function description(): string
    {
        $write = self::writeAllowed() ? 'INSERT, UPDATE and DELETE are allowed' : 'the server is in read-only mode (CIPI_MCP_DB_WRITE=false)';

        return "Run a SQL query against the application database for investigation and debugging. SELECT, WITH, SHOW, DESCRIBE and EXPLAIN return rows as a table (or JSON); {$write}. DDL and administrative statements (DROP, ALTER, TRUNCATE, GRANT, file I/O, …) are blocked. SELECTs without a LIMIT are capped automatically. Results are production data.";
    }

    public function inputSchema(): array
    {
        return self::schema([
            'query' => ['type' => 'string', 'description' => 'The SQL statement (e.g. "SELECT id, email FROM users WHERE created_at > NOW() - INTERVAL 1 DAY").', 'maxLength' => 20000],
            'limit' => ['type' => 'integer', 'description' => 'Maximum rows to return for reads. Default '.self::maxRows().', max 500.', 'minimum' => 1, 'maximum' => 500],
            'format' => ['type' => 'string', 'enum' => ['table', 'json'], 'default' => 'table', 'description' => 'Row rendering in the text output. Structured content always carries the rows as JSON.'],
        ], ['query']);
    }

    public function annotations(): array
    {
        $write = self::writeAllowed();

        return ['readOnlyHint' => ! $write, 'destructiveHint' => $write, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public static function writeAllowed(): bool
    {
        return (bool) config('cipi.mcp_db_write', true);
    }

    public static function maxRows(): int
    {
        return max(1, min(500, (int) config('cipi.mcp_max_rows', 100)));
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $query = trim(self::str($arguments, 'query', '') ?? '');
        if ($query === '') {
            return ToolResult::error('The "query" argument is required.');
        }
        $query = rtrim($query, "; \t\n\r");
        if (str_contains($query, ';')) {
            return ToolResult::error('One statement per call: remove the extra ";".');
        }
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                return ToolResult::error('This query contains a blocked operation. DDL (DROP, ALTER, TRUNCATE, CREATE …), GRANT/REVOKE, file I/O and administrative statements are not allowed via MCP.');
            }
        }

        $limit = self::int($arguments, 'limit', self::maxRows(), 1, 500);
        $format = self::str($arguments, 'format', 'table');
        $isRead = (bool) preg_match('/^(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN|VALUES|TABLE)\b/i', $query);

        try {
            if ($isRead) {
                return $this->read($query, $limit, $format === 'json');
            }
            if (! self::writeAllowed()) {
                return ToolResult::error('This server is read-only for MCP (CIPI_MCP_DB_WRITE=false): only SELECT/WITH/SHOW/DESCRIBE/EXPLAIN are allowed.');
            }

            return $this->write($query);
        } catch (\Throwable $e) {
            return ToolResult::error('SQL Error: '.$e->getMessage());
        }
    }

    private function read(string $query, int $limit, bool $json): ToolResult
    {
        $sql = $query;
        $capped = false;
        if (preg_match('/^(SELECT|WITH|VALUES|TABLE)\b/i', $query) && ! preg_match('/\bLIMIT\s+\d+/i', $query) && ! preg_match('/\bFOR\s+(UPDATE|SHARE)\b/i', $query)) {
            $sql .= ' LIMIT '.($limit + 1);
            $capped = true;
        }

        $rows = array_map(fn ($r) => (array) $r, DB::select($sql));
        $total = count($rows);
        $truncated = $total > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ($rows === []) {
            return ToolResult::text('(0 rows returned)', ['rows' => [], 'row_count' => 0, 'truncated' => false]);
        }

        $label = count($rows) === 1 ? '1 row' : count($rows).' rows';
        $note = $truncated ? "\n\n({$label} shown — more rows exist, add a LIMIT or raise `limit`".($capped ? '' : '').')' : "\n\n({$label})";
        $text = ($json ? ToolResult::pretty($rows) : self::table($rows)).$note;

        return ToolResult::text($text, ['rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated]);
    }

    private function write(string $query): ToolResult
    {
        if (preg_match('/^INSERT\b/i', $query)) {
            DB::insert($query);

            return ToolResult::text('Insert executed successfully.', ['affected' => null]);
        }
        if (preg_match('/^UPDATE\b/i', $query)) {
            $n = DB::update($query);

            return ToolResult::text("Update executed. Rows affected: {$n}", ['affected' => $n]);
        }
        if (preg_match('/^DELETE\b/i', $query)) {
            $n = DB::delete($query);

            return ToolResult::text("Delete executed. Rows affected: {$n}", ['affected' => $n]);
        }
        if (preg_match('/^(CREATE|ALTER|DROP|RENAME|COMMENT|VACUUM|ANALYZE|OPTIMIZE|REPAIR|FLUSH|RESET|LOCK|UNLOCK|CALL|DO|HANDLER|PREPARE|EXECUTE|DEALLOCATE|USE)\b/i', $query)) {
            return ToolResult::error('Only INSERT, UPDATE and DELETE are allowed as write statements via MCP.');
        }
        $ok = DB::statement($query);

        return ToolResult::text('Statement executed. Success: '.($ok ? 'true' : 'false'), ['success' => $ok]);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function table(array $rows): string
    {
        $columns = array_keys($rows[0]);
        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = mb_strlen((string) $col);
        }
        $cells = [];
        foreach ($rows as $i => $row) {
            foreach ($columns as $col) {
                $v = self::cell($row[$col] ?? null);
                $cells[$i][$col] = $v;
                $widths[$col] = min(60, max($widths[$col], mb_strlen($v)));
            }
        }
        $pad = fn (string $s, int $w) => $s.str_repeat(' ', max(0, $w - mb_strlen($s)));
        $lines = [
            '| '.implode(' | ', array_map(fn ($c) => $pad((string) $c, $widths[$c]), $columns)).' |',
            '|-'.implode('-|-', array_map(fn ($c) => str_repeat('-', $widths[$c]), $columns)).'-|',
        ];
        foreach ($cells as $row) {
            $lines[] = '| '.implode(' | ', array_map(fn ($c) => $pad(mb_substr($row[$c], 0, $widths[$c]), $widths[$c]), $columns)).' |';
        }

        return implode("\n", $lines);
    }

    private static function cell(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_array($v) || is_object($v)) {
            return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return str_replace(["\r", "\n"], ['\\r', '\\n'], (string) $v);
    }
}
