<?php

namespace Cipi\Agent\Anonymizer\Dialect;

use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\SqlValues;
use Cipi\Agent\Anonymizer\Stats;
use Cipi\Agent\Anonymizer\Transformer;

/**
 * mysqldump / mariadb-dump output: `CREATE TABLE` blocks give the column
 * order, `INSERT INTO `t` VALUES (...),(...);` lines carry the rows
 * (extended inserts, one statement per line; `--complete-insert` adds the
 * column list, which takes precedence when present).
 */
final class MySqlDumpProcessor implements DumpProcessor
{
    private readonly InsertRewriter $rewriter;

    public function __construct(private readonly Config $config, Transformer $transformer)
    {
        $this->rewriter = new InsertRewriter($config, $transformer, SqlValues::MYSQL);
    }

    public function process(string $inputPath, string $outputPath): Stats
    {
        $stats = new Stats;
        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Failed to open dump files for processing');
        }

        /** @var array<string, list<string>> $columnsByTable */
        $columnsByTable = [];
        $creating = null;

        while (($line = fgets($in)) !== false) {
            if ($creating !== null) {
                if (preg_match('/^\s*`([^`]+)`\s/', $line, $m)) {
                    $columnsByTable[$creating][] = $m[1];
                } elseif (str_starts_with($line, ')')) {
                    $creating = null;
                }
                fwrite($out, $line);

                continue;
            }

            if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`?(?:[^`.\s]+`?\.`?)?([^`\s(]+)`?\s*\(/i', $line, $m)) {
                $creating = $m[1];
                $columnsByTable[$creating] = [];
                if (str_contains($line, ');') || preg_match('/\)\s*ENGINE=/i', $line)) {
                    $creating = null;
                }
                fwrite($out, $line);

                continue;
            }

            if (str_starts_with($line, 'INSERT INTO') || str_starts_with($line, 'REPLACE INTO')) {
                if (preg_match('/^(INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(?:[^`.\s]+`?\.`?)?([^`\s(]+)`?\s*(\(([^)]*)\))?\s*VALUES\s*/i', $line, $m, PREG_OFFSET_CAPTURE)) {
                    $table = $m[2][0];
                    if ($this->config->truncates($table)) {
                        $stats->dropped($table);

                        continue;
                    }
                    $specs = $this->config->specsFor($table);
                    if ($specs !== []) {
                        $columns = isset($m[4]) && $m[4][0] !== ''
                            ? array_map(fn (string $c) => trim($c, " `\t"), explode(',', $m[4][0]))
                            : ($columnsByTable[$table] ?? []);
                        if ($columns === []) {
                            $stats->warnings[] = "{$table}: INSERT found before its CREATE TABLE — rows left unchanged";
                        } else {
                            $head = substr($line, 0, strlen($m[0][0]));
                            $body = substr($line, strlen($m[0][0]));
                            $line = $head.$this->rewriter->rewrite($table, $columns, $specs, $body, $stats);
                        }
                    }
                }
            }

            fwrite($out, $line);
        }

        fclose($in);
        fclose($out);

        return $stats;
    }
}
