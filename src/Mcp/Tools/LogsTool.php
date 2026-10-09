<?php

namespace Cipi\Agent\Mcp\Tools;

use Cipi\Agent\Mcp\Context;
use Cipi\Agent\Mcp\Tool;
use Cipi\Agent\Mcp\ToolResult;
use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\DeployInfo;
use Cipi\Agent\Support\Redactor;

final class LogsTool extends Tool
{
    public const TYPES = ['laravel', 'nginx', 'php', 'worker', 'horizon', 'reverb', 'deploy', 'all'];

    public const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    private const MAX_OUTPUT = 200_000;

    public function name(): string
    {
        return 'logs';
    }

    public function title(): string
    {
        return 'Read logs';
    }

    public function description(): string
    {
        return 'Read the tail of application and infrastructure logs: Laravel (storage/logs), nginx access/error, PHP-FPM, Supervisor queue workers, Horizon, Reverb and the deploy log — the same types as `cipi app logs`. Laravel entries can be filtered by minimum severity and all logs by keyword; multi-line entries (stack traces) stay intact. Production content: common secrets are redacted.';
    }

    public function inputSchema(): array
    {
        return self::schema([
            'type' => ['type' => 'string', 'enum' => self::TYPES, 'default' => 'laravel', 'description' => 'laravel = app log (storage/logs); nginx = access + error; php = PHP-FPM error log; worker = Supervisor queue workers; horizon; reverb; deploy = Deployer output; all = every file.'],
            'lines' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 500, 'description' => 'Lines per file before filtering (default 50, max 500).'],
            'level' => ['type' => 'string', 'enum' => array_keys(self::LEVELS), 'description' => 'Minimum severity for Laravel entries (error = error, critical, alert, emergency).'],
            'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Case-insensitive keyword; only matching entries are returned.'],
        ]);
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, Context $context): ToolResult
    {
        $type = strtolower(self::str($arguments, 'type', 'laravel') ?? 'laravel');
        if (! in_array($type, self::TYPES, true)) {
            return ToolResult::error("Invalid type '{$type}'. Valid: ".implode(', ', self::TYPES));
        }
        $lines = self::int($arguments, 'lines', 50, 1, 500);
        $level = self::str($arguments, 'level');
        $level = $level !== null && $level !== '' ? strtolower($level) : null;
        if ($level !== null && ! isset(self::LEVELS[$level])) {
            return ToolResult::error("Invalid level '{$level}'. Valid: ".implode(', ', array_keys(self::LEVELS)));
        }
        $search = self::str($arguments, 'search');
        $search = $search !== '' ? $search : null;

        $files = self::resolve($type);
        if ($files === []) {
            $hint = $type !== 'laravel' && AppHome::user() === null ? ' CIPI_APP_USER is not configured — infrastructure logs live in /home/<app>/logs.' : '';

            return ToolResult::error("No log files found for type '{$type}'.{$hint}");
        }

        $sections = [];
        $matched = 0;
        foreach ($files as $label => [$path, $kind]) {
            if (! is_file($path)) {
                $sections[] = "--- {$label} ---\nFile not found: {$path}";

                continue;
            }
            if (filesize($path) === 0) {
                $sections[] = "--- {$label} ---\n(empty)";

                continue;
            }
            $raw = DeployInfo::tail($path, $lines);
            $content = $kind === 'laravel'
                ? self::filterLaravel($raw, $level, $search)
                : ($search !== null ? array_values(array_filter($raw, fn (string $l) => stripos($l, $search) !== false)) : $raw);
            $matched += count($content);
            $text = $content === [] ? '(no matching entries)' : implode('', $content);
            $sections[] = count($files) > 1 ? "--- {$label} ---\n{$text}" : $text;
        }

        $output = implode("\n\n", $sections);
        if (strlen($output) > self::MAX_OUTPUT) {
            $output = '… (truncated to the last '.self::MAX_OUTPUT." bytes)\n".substr($output, -self::MAX_OUTPUT);
        }

        return ToolResult::text(Redactor::logs($output), [
            'type' => $type,
            'files' => array_map(fn (array $f) => $f[0], $files),
            'lines_requested' => $lines,
            'lines_returned' => $matched,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}> label => [path, kind]
     */
    public static function resolve(string $type): array
    {
        $files = [];
        $logs = AppHome::logsDir();

        $add = static function (array &$files, string $glob, string $kind, bool $latestOnly = false): void {
            $found = glob($glob) ?: [];
            if ($latestOnly && $found !== []) {
                rsort($found);
                $found = [$found[0]];
            }
            sort($found);
            foreach ($found as $path) {
                $files[basename($path)] = [$path, $kind];
            }
        };

        if ($type === 'laravel' || $type === 'all') {
            $dir = storage_path('logs');
            if (is_file("{$dir}/laravel.log")) {
                $files['laravel.log'] = ["{$dir}/laravel.log", 'laravel'];
            } else {
                $add($files, "{$dir}/laravel-*.log", 'laravel', true);
            }
        }
        if ($logs !== null && is_dir($logs)) {
            if ($type === 'nginx' || $type === 'all') {
                $add($files, "{$logs}/nginx-*.log", 'plain');
            }
            if ($type === 'php' || $type === 'all') {
                $add($files, "{$logs}/php-fpm-*.log", 'plain');
            }
            if ($type === 'worker' || $type === 'all') {
                $add($files, "{$logs}/worker-*.log", 'plain');
            }
            if ($type === 'horizon' || $type === 'all') {
                $add($files, "{$logs}/horizon*.log", 'plain');
            }
            if ($type === 'reverb' || $type === 'all') {
                $add($files, "{$logs}/reverb*.log", 'plain');
            }
            if ($type === 'deploy' || $type === 'all') {
                if (is_file("{$logs}/deploy.log")) {
                    $files['deploy.log'] = ["{$logs}/deploy.log", 'plain'];
                }
            }
        }

        return $files;
    }

    /**
     * @param  list<string>  $raw
     * @return list<string>
     */
    private static function filterLaravel(array $raw, ?string $minLevel, ?string $search): array
    {
        if ($minLevel === null && $search === null) {
            return $raw;
        }
        $entries = [];
        $current = null;
        foreach ($raw as $line) {
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}[\sT]/', $line)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['header' => $line, 'lines' => [$line]];
            } elseif ($current !== null) {
                $current['lines'][] = $line;
            } else {
                $entries[] = ['header' => '', 'lines' => [$line]];
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        if ($minLevel !== null) {
            $min = self::LEVELS[$minLevel];
            $entries = array_filter($entries, function (array $e) use ($min): bool {
                if (preg_match('/\]\s+\S+\.(\w+)\s*:/', $e['header'], $m)) {
                    return (self::LEVELS[strtolower($m[1])] ?? -1) >= $min;
                }

                return false;
            });
        }
        if ($search !== null) {
            $entries = array_filter($entries, fn (array $e) => stripos(implode('', $e['lines']), $search) !== false);
        }

        $out = [];
        foreach ($entries as $e) {
            array_push($out, ...$e['lines']);
        }

        return $out;
    }
}
