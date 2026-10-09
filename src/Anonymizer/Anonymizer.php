<?php

namespace Cipi\Agent\Anonymizer;

use Cipi\Agent\Anonymizer\Dialect\MySqlDumpProcessor;
use Cipi\Agent\Anonymizer\Dialect\PostgresDumpProcessor;

/**
 * Dump → transform → deliver. One run produces one anonymized SQL file
 * (optionally gzip-compressed) and a report of what was changed.
 */
final class Anonymizer
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  callable(string): void|null  $log
     * @return array<string, mixed>
     */
    public function run(string $outputPath, ?string $tempDir = null, ?bool $gzip = null, ?callable $log = null): array
    {
        $started = microtime(true);
        $log ??= static fn (string $m) => null;
        $gzip ??= (bool) $this->config->options['gzip'];
        $connection = $this->config->options['connection'] ?? null;

        $dumper = Dumper::forConnection($connection, $this->config->options['dump_args'] ?? []);
        $dialect = $dumper->dialect();

        $tempDir = $tempDir ?: sys_get_temp_dir().'/cipi-anonymize-'.bin2hex(random_bytes(6));
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0700, true)) {
            throw new \RuntimeException("Cannot create temp directory {$tempDir}");
        }
        @chmod($tempDir, 0700);
        $original = "{$tempDir}/original.sql";
        $anonymized = "{$tempDir}/anonymized.sql";

        try {
            $log("Dumping database ({$dialect}) …");
            $dumper->dump($original);
            @chmod($original, 0600);
            $log('Dump: '.self::human((int) filesize($original)));

            $log('Applying transformations …');
            $transformer = new Transformer($this->config);
            $processor = $dialect === SqlValues::MYSQL
                ? new MySqlDumpProcessor($this->config, $transformer)
                : new PostgresDumpProcessor($this->config, $transformer);
            $stats = $processor->process($original, $anonymized);
            @unlink($original); // the real data does not linger a second longer than needed

            $log('Writing '.($gzip ? 'compressed ' : '')."output …");
            $dir = dirname($outputPath);
            if (! is_dir($dir) && ! @mkdir($dir, 0750, true)) {
                throw new \RuntimeException("Cannot create output directory {$dir}");
            }
            if ($gzip) {
                if (! function_exists('gzopen')) {
                    throw new \RuntimeException('ext-zlib is required for --gzip');
                }
                if (! str_ends_with($outputPath, '.gz')) {
                    $outputPath .= '.gz';
                }
                self::gzipFile($anonymized, $outputPath);
                @unlink($anonymized);
            } else {
                if (! @rename($anonymized, $outputPath)) {
                    if (! @copy($anonymized, $outputPath)) {
                        throw new \RuntimeException("Cannot write {$outputPath}");
                    }
                    @unlink($anonymized);
                }
            }
            @chmod($outputPath, 0600);
        } finally {
            self::cleanup($tempDir);
        }

        return [
            'output' => $outputPath,
            'bytes' => (int) filesize($outputPath),
            'size' => self::human((int) filesize($outputPath)),
            'gzip' => $gzip,
            'dialect' => $dialect,
            'connection' => $connection ?? (string) config('database.default'),
            'duration_seconds' => round(microtime(true) - $started, 2),
        ] + $stats->toArray();
    }

    private static function gzipFile(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        if ($in === false || $out === false) {
            throw new \RuntimeException("Cannot compress to {$target}");
        }
        while (! feof($in)) {
            $chunk = fread($in, 1 << 20);
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
        }
        fclose($in);
        gzclose($out);
    }

    public static function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    public static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return ($unit === 'B' ? $bytes : round($bytes, 1)).' '.$unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
