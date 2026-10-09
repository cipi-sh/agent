<?php

namespace Cipi\Agent\Anonymizer\Dialect;

use Cipi\Agent\Anonymizer\Config;
use Cipi\Agent\Anonymizer\CopyText;
use Cipi\Agent\Anonymizer\SqlValues;
use Cipi\Agent\Anonymizer\Stats;
use Cipi\Agent\Anonymizer\Transformer;

/**
 * pg_dump plain-format output. Rows arrive as `COPY schema.table (cols) FROM
 * stdin;` blocks terminated by `\.` (the default), or as INSERT statements
 * when the dump was taken with --inserts / --column-inserts.
 */
final class PostgresDumpProcessor implements DumpProcessor
{
    private readonly InsertRewriter $rewriter;

    public function __construct(private readonly Config $config, private readonly Transformer $transformer)
    {
        $this->rewriter = new InsertRewriter($config, $transformer, SqlValues::PGSQL);
    }

    public function process(string $inputPath, string $outputPath): Stats
    {
        $stats = new Stats;
        $in = fopen($inputPath, 'rb');
        $out = fopen($outputPath, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Failed to open dump files for processing');
        }

        $copyTable = null;
        $copyColumns = [];
        $copySpecs = [];
        $copyTruncate = false;

        while (($line = fgets($in)) !== false) {
            if ($copyTable !== null) {
                if ($line === "\\.\n" || $line === "\\.\r\n" || rtrim($line) === '\\.') {
                    $copyTable = null;
                    fwrite($out, $line);

                    continue;
                }
                if ($copyTruncate) {
                    $stats->dropped($copyTable);

                    continue;
                }
                if ($copySpecs !== []) {
                    $line = $this->rewriteCopyRow($copyTable, $copyColumns, $copySpecs, $line, $stats);
                }
                fwrite($out, $line);

                continue;
            }

            if (str_starts_with($line, 'COPY ') && preg_match('/^COPY\s+(?:"?([^".\s]+)"?\.)?"?([^"\s(]+)"?\s*\(([^)]*)\)\s+FROM\s+stdin;/i', $line, $m)) {
                $copyTable = $m[2];
                $copyColumns = array_map(fn (string $c) => trim($c, " \"\t"), explode(',', $m[3]));
                $copySpecs = $this->config->specsFor($copyTable);
                $copyTruncate = $this->config->truncates($copyTable);
                fwrite($out, $line);

                continue;
            }

            if (str_starts_with($line, 'INSERT INTO') && preg_match('/^INSERT\s+INTO\s+(?:"?([^".\s]+)"?\.)?"?([^"\s(]+)"?\s*(\(([^)]*)\))?\s*(?:OVERRIDING\s+SYSTEM\s+VALUE\s+)?VALUES\s*/i', $line, $m, PREG_OFFSET_CAPTURE)) {
                $table = $m[2][0];
                if ($this->config->truncates($table)) {
                    $stats->dropped($table);

                    continue;
                }
                $specs = $this->config->specsFor($table);
                if ($specs !== []) {
                    if (! isset($m[4]) || $m[4][0] === '') {
                        $stats->warnings[] = "{$table}: INSERT without a column list (dump with --column-inserts) — rows left unchanged";
                    } else {
                        $columns = array_map(fn (string $c) => trim($c, " \"\t"), explode(',', $m[4][0]));
                        $head = substr($line, 0, strlen($m[0][0]));
                        $body = substr($line, strlen($m[0][0]));
                        $line = $head.$this->rewriter->rewrite($table, $columns, $specs, $body, $stats);
                    }
                }
            }

            fwrite($out, $line);
        }

        fclose($in);
        fclose($out);

        return $stats;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, \Cipi\Agent\Anonymizer\Spec>  $specs
     */
    private function rewriteCopyRow(string $table, array $columns, array $specs, string $line, Stats $stats): string
    {
        $fields = CopyText::decodeRow($line);
        if (count($fields) !== count($columns)) {
            $stats->warnings[] = "{$table}: a COPY row has ".count($fields).' fields for '.count($columns).' columns — left unchanged';

            return $line;
        }
        $count = 0;
        foreach ($columns as $i => $name) {
            if (! isset($specs[$name])) {
                continue;
            }
            $new = $this->transformer->transform($table, $name, $fields[$i], $specs[$name]);
            if ($new === $fields[$i]) {
                continue;
            }
            $fields[$i] = $new === null ? null : (is_bool($new) ? ($new ? 't' : 'f') : (string) $new);
            $count++;
        }
        $stats->row($table, $count);

        return CopyText::encodeRow($fields);
    }
}
