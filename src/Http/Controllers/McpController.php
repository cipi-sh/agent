<?php

namespace Cipi\Agent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class McpController extends Controller
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private const SERVER_VERSION = '1.0.0';

    private const BLOCKED_ARTISAN_COMMANDS = [
        'serve', 'tinker', 'queue:work', 'queue:listen',
        'schedule:work', 'horizon', 'octane:start', 'reverb:start',
    ];

    private const LOG_LEVELS = [
        'debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3,
        'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7,
    ];

    private const BLOCKED_SQL_PATTERNS = [
        '/\bDROP\s+(DATABASE|SCHEMA|TABLE|VIEW)\b/i',
        '/\bCREATE\s+(DATABASE|SCHEMA)\b/i',
        '/\bTRUNCATE\b/i',
        '/\bLOAD\s+DATA\b/i',
        '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i',
        '/\bGRANT\s/i',
        '/\bREVOKE\s/i',
    ];

    private const MAX_QUERY_ROWS = 100;

    public function handle(Request $request): JsonResponse|Response
    {
        $body = $request->json()->all();

        if (empty($body)) {
            return response()->json($this->error(null, -32700, 'Parse error: empty or invalid JSON'), 400);
        }

        // Batch request (array of messages)
        if (isset($body[0]) && is_array($body[0])) {
            $responses = array_filter(
                array_map(fn ($msg) => $this->dispatch($msg), $body)
            );
            return response()->json(array_values($responses));
        }

        $result = $this->dispatch($body);

        // Notifications (no id) return HTTP 200 with no body
        if ($result === null) {
            return response('', 200);
        }

        return response()->json($result);
    }

    private function dispatch(array $message): ?array
    {
        $method = $message['method'] ?? null;
        $params = $message['params'] ?? [];
        $hasId  = array_key_exists('id', $message);
        $id     = $message['id'] ?? null;

        // Notification — no response required
        if (! $hasId) {
            return null;
        }

        return match ($method) {
            'initialize'  => $this->initialize($id),
            'ping'        => $this->ping($id),
            'tools/list'  => $this->toolsList($id),
            'tools/call'  => $this->toolsCall($id, $params),
            default       => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    // -------------------------------------------------------------------------
    // Protocol methods
    // -------------------------------------------------------------------------

    private function initialize(mixed $id): array
    {
        $appUser = config('cipi.app_user', 'unknown');

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'serverInfo'      => [
                    'name'    => 'cipi-agent',
                    'version' => self::SERVER_VERSION,
                ],
                'capabilities' => [
                    'tools' => new \stdClass(),
                ],
                'instructions' => "Cipi Agent MCP Server for app '{$appUser}'. "
                    . "Use 'health' to check app status, 'app_info' for configuration details, "
                    . "'deploy' to trigger a deployment, 'logs' to read application and infrastructure logs "
                    . "(supports type: laravel/nginx/php/worker/deploy, severity filtering, and keyword search), "
                    . "'artisan' to run Artisan commands, and 'db_query' to execute SQL queries for data investigation.",
            ],
        ];
    }

    private function ping(mixed $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];
    }

    private function toolsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'tools' => [
                    [
                        'name'        => 'health',
                        'description' => 'Check the health status of this Laravel application. Returns status of the app, database, cache, and queue worker.',
                        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                    ],
                    [
                        'name'        => 'app_info',
                        'description' => 'Get detailed information about this application: app user, PHP version, Laravel version, environment, queue/cache drivers, and Cipi configuration.',
                        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                    ],
                    [
                        'name'        => 'deploy',
                        'description' => 'Trigger a new zero-downtime deployment for this application. Writes a deploy trigger file; the Cipi cron picks it up and runs Deployer within 1 minute.',
                        'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                    ],
                    [
                        'name'        => 'logs',
                        'description' => 'Read application and infrastructure logs. Supports Laravel app logs, Nginx, PHP-FPM, queue worker, and deploy logs with optional severity filtering and keyword search. Equivalent to "cipi app logs <app> --type=<type>" on the CLI.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'type' => [
                                    'type'        => 'string',
                                    'enum'        => ['laravel', 'nginx', 'php', 'worker', 'deploy'],
                                    'description' => 'Log type. "laravel" = application logs from storage/logs/ (daily or single). "nginx" = Nginx access + error logs. "php" = PHP-FPM error log. "worker" = Supervisor queue worker logs (all queues). "deploy" = Deployer output log.',
                                    'default'     => 'laravel',
                                ],
                                'lines' => [
                                    'type'        => 'integer',
                                    'description' => 'Number of log lines to return per file. Default: 50. Max: 500.',
                                    'default'     => 50,
                                ],
                                'level' => [
                                    'type'        => 'string',
                                    'enum'        => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
                                    'description' => 'Minimum severity level for Laravel logs. Returns entries at this level and above (e.g. "error" includes error, critical, alert, emergency). Only applies when type is "laravel".',
                                ],
                                'search' => [
                                    'type'        => 'string',
                                    'description' => 'Case-insensitive keyword filter. Only entries containing this string are returned. For Laravel logs, matches against the full multi-line entry.',
                                ],
                            ],
                            'required' => [],
                        ],
                    ],
                    [
                        'name'        => 'artisan',
                        'description' => 'Run an Artisan command on this Laravel application (e.g. "migrate:status", "queue:size", "cache:clear"). Long-running and interactive commands are blocked.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'command' => [
                                    'type'        => 'string',
                                    'description' => 'The Artisan command to run, without the "php artisan" prefix (e.g. "migrate:status").',
                                ],
                            ],
                            'required' => ['command'],
                        ],
                    ],
                    [
                        'name'        => 'db_query',
                        'description' => 'Execute SQL queries against the application database for data investigation and debugging. Supports SELECT (read) and INSERT/UPDATE/DELETE (write). Destructive DDL (DROP TABLE, TRUNCATE, etc.) is blocked. Results are limited to 100 rows. Equivalent to running queries in "cipi app tinker".',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'query' => [
                                    'type'        => 'string',
                                    'description' => 'The SQL query to execute (e.g. "SELECT * FROM users WHERE id = 1", "SELECT COUNT(*) FROM failed_jobs", "UPDATE settings SET value = \'true\' WHERE key = \'maintenance\'").',
                                ],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function toolsCall(mixed $id, array $params): array
    {
        $name      = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        $text = match ($name) {
            'health'   => $this->runHealth(),
            'app_info' => $this->runAppInfo(),
            'deploy'   => $this->runDeploy(),
            'logs'     => $this->runLogs($arguments),
            'artisan'  => $this->runArtisan($arguments),
            'db_query' => $this->runDbQuery($arguments),
            default    => null,
        };

        if ($text === null) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => is_string($text) ? $text : json_encode($text, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Tools
    // -------------------------------------------------------------------------

    private function runHealth(): array
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok' => true, 'database' => DB::connection()->getDatabaseName()];
        } catch (\Throwable $e) {
            $checks['database'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $key = 'cipi_mcp_' . md5(config('cipi.app_user', 'cipi'));
            Cache::put($key, true, 10);
            $hit = Cache::get($key) === true;
            Cache::forget($key);
            $checks['cache'] = ['ok' => $hit, 'driver' => config('cache.default', 'file')];
        } catch (\Throwable $e) {
            $checks['cache'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $checks['queue'] = [
                'ok'           => true,
                'connection'   => config('queue.default', 'sync'),
                'pending_jobs' => Queue::size(),
            ];
        } catch (\Throwable $e) {
            $checks['queue'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $healthy = collect($checks)->every(fn ($c) => $c['ok']);

        return [
            'status'      => $healthy ? 'healthy' : 'degraded',
            'app_user'    => config('cipi.app_user', 'unknown'),
            'php'         => PHP_VERSION,
            'laravel'     => app()->version(),
            'environment' => app()->environment(),
            'timestamp'   => now()->toIso8601String(),
            'checks'      => $checks,
        ];
    }

    private function runAppInfo(): array
    {
        $prefix = config('cipi.route_prefix', 'cipi');

        return [
            'app_user'        => config('cipi.app_user', 'unknown'),
            'app_name'        => config('app.name'),
            'app_url'         => config('app.url'),
            'environment'     => app()->environment(),
            'debug'           => config('app.debug'),
            'laravel'         => app()->version(),
            'php'             => PHP_VERSION,
            'php_cipi'        => config('cipi.php_version', PHP_VERSION),
            'deploy_branch'   => config('cipi.deploy_branch') ?? 'any',
            'webhook_url'     => url("{$prefix}/webhook"),
            'health_url'      => url("{$prefix}/health"),
            'mcp_url'         => url("{$prefix}/mcp"),
            'queue_driver'    => config('queue.default', 'sync'),
            'cache_driver'    => config('cache.default', 'file'),
            'session_driver'  => config('session.driver', 'file'),
            'db_connection'   => config('database.default'),
            'db_name'         => config('database.connections.' . config('database.default') . '.database'),
            'timezone'        => config('app.timezone'),
            'locale'          => config('app.locale'),
        ];
    }

    private function runDeploy(): array
    {
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            return ['success' => false, 'error' => 'CIPI_APP_USER is not configured'];
        }

        $triggerFile = "/home/{$appUser}/.deploy-trigger";
        $payload     = json_encode([
            'branch'    => config('cipi.deploy_branch'),
            'trigger'   => 'mcp',
            'timestamp' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);

        if (@file_put_contents($triggerFile, $payload) === false) {
            return ['success' => false, 'error' => "Cannot write deploy trigger to {$triggerFile}"];
        }

        return [
            'success'      => true,
            'message'      => 'Deploy queued. Deployer will run within 1 minute.',
            'app'          => $appUser,
            'trigger_file' => $triggerFile,
            'queued_at'    => now()->toIso8601String(),
        ];
    }

    private function runLogs(array $arguments): string
    {
        $type   = $arguments['type'] ?? 'laravel';
        $lines  = min(max((int) ($arguments['lines'] ?? 50), 1), 500);
        $level  = isset($arguments['level']) ? strtolower($arguments['level']) : null;
        $search = $arguments['search'] ?? null;

        if ($level !== null && ! isset(self::LOG_LEVELS[$level])) {
            return "Invalid level '{$level}'. Valid: " . implode(', ', array_keys(self::LOG_LEVELS));
        }

        $files = match ($type) {
            'nginx'  => $this->resolveInfraLogs('nginx'),
            'php'    => $this->resolveInfraLogs('php'),
            'worker' => $this->resolveInfraLogs('worker'),
            'deploy' => $this->resolveInfraLogs('deploy'),
            default  => $this->resolveLaravelLogs(),
        };

        if (empty($files)) {
            $hint = ($type !== 'laravel' && empty(config('cipi.app_user')))
                ? ' CIPI_APP_USER is not configured — infrastructure logs require it.'
                : '';

            return "No log files found for type '{$type}'.{$hint}";
        }

        $sections = [];

        foreach ($files as $label => $path) {
            if (! file_exists($path)) {
                $sections[] = "--- {$label} ---\nFile not found: {$path}";
                continue;
            }

            if (filesize($path) === 0) {
                $sections[] = "--- {$label} ---\n(empty)";
                continue;
            }

            $rawLines = $this->tailFile($path, $lines);

            if ($type === 'laravel') {
                $content = $this->processLaravelLines($rawLines, $level, $search);
            } elseif ($search !== null) {
                $content = array_values(array_filter(
                    $rawLines,
                    fn (string $line) => stripos($line, $search) !== false
                ));
            } else {
                $content = $rawLines;
            }

            $text = empty($content) ? '(no matching entries)' : implode('', $content);
            $sections[] = count($files) > 1
                ? "--- {$label} ---\n{$text}"
                : $text;
        }

        return implode("\n\n", $sections) ?: '(no content)';
    }

    private function resolveLaravelLogs(): array
    {
        $dir   = storage_path('logs');
        $files = [];

        $dailyLogs = glob($dir . '/laravel-*.log') ?: [];

        if (! empty($dailyLogs)) {
            rsort($dailyLogs);
            $files[basename($dailyLogs[0])] = $dailyLogs[0];
        } elseif (file_exists($dir . '/laravel.log')) {
            $files['laravel.log'] = $dir . '/laravel.log';
        }

        return $files;
    }

    private function resolveInfraLogs(string $type): array
    {
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            return [];
        }

        $dir = "/home/{$appUser}/logs";

        if (! is_dir($dir)) {
            return [];
        }

        return match ($type) {
            'nginx' => array_filter([
                'nginx-error.log'  => $dir . '/nginx-error.log',
                'nginx-access.log' => $dir . '/nginx-access.log',
            ], 'file_exists'),

            'php' => file_exists($dir . '/php-fpm-error.log')
                ? ['php-fpm-error.log' => $dir . '/php-fpm-error.log']
                : [],

            'worker' => $this->resolveWorkerLogs($dir),

            'deploy' => file_exists($dir . '/deploy.log')
                ? ['deploy.log' => $dir . '/deploy.log']
                : [],

            default => [],
        };
    }

    private function resolveWorkerLogs(string $dir): array
    {
        $found = glob($dir . '/worker-*.log') ?: [];
        $files = [];

        foreach ($found as $path) {
            $files[basename($path)] = $path;
        }

        ksort($files);

        return $files;
    }

    private function tailFile(string $path, int $lines): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $total = $file->key();
        $start = max(0, $total - $lines);

        $file->seek($start);
        $buffer = [];

        while (! $file->eof()) {
            $line = $file->fgets();
            if ($line !== false) {
                $buffer[] = $line;
            }
        }

        return $buffer;
    }

    private function processLaravelLines(array $rawLines, ?string $minLevel, ?string $search): array
    {
        if ($minLevel === null && $search === null) {
            return $rawLines;
        }

        $entries = $this->parseLaravelEntries($rawLines);

        if ($minLevel !== null) {
            $minSeverity = self::LOG_LEVELS[$minLevel];
            $entries = array_filter($entries, function (array $entry) use ($minSeverity) {
                if (preg_match('/\]\s+\S+\.(\w+)\s*:/', $entry['header'], $m)) {
                    return (self::LOG_LEVELS[strtolower($m[1])] ?? -1) >= $minSeverity;
                }
                return false;
            });
        }

        if ($search !== null) {
            $entries = array_filter($entries, function (array $entry) use ($search) {
                foreach ($entry['lines'] as $line) {
                    if (stripos($line, $search) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        $result = [];
        foreach ($entries as $entry) {
            array_push($result, ...$entry['lines']);
        }

        return $result;
    }

    private function parseLaravelEntries(array $lines): array
    {
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
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

        return $entries;
    }

    private function runDbQuery(array $arguments): string
    {
        $query = trim($arguments['query'] ?? '');

        if (empty($query)) {
            return 'Error: the "query" argument is required.';
        }

        foreach (self::BLOCKED_SQL_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                return 'Error: this query contains a blocked operation. '
                    . 'DROP TABLE/DATABASE, TRUNCATE, GRANT/REVOKE, and file operations are not allowed via MCP.';
            }
        }

        $normalized = ltrim($query);
        $isRead     = (bool) preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $normalized);

        try {
            if ($isRead) {
                return $this->executeReadQuery($query);
            }

            return $this->executeWriteQuery($query, $normalized);
        } catch (\Throwable $e) {
            return 'SQL Error: ' . $e->getMessage();
        }
    }

    private function executeReadQuery(string $query): string
    {
        $results = DB::select($query);
        $count   = count($results);

        if ($count === 0) {
            return '(0 rows returned)';
        }

        $truncated = $count > self::MAX_QUERY_ROWS;
        $slice     = $truncated
            ? array_slice($results, 0, self::MAX_QUERY_ROWS)
            : $results;

        $output = $this->formatQueryResults($slice);
        $label  = $count === 1 ? '1 row' : "{$count} rows";

        if ($truncated) {
            $output .= "\n\n({$label} total, showing first " . self::MAX_QUERY_ROWS . ')';
        } else {
            $output .= "\n\n({$label})";
        }

        return $output;
    }

    private function executeWriteQuery(string $query, string $normalized): string
    {
        if (preg_match('/^INSERT\b/i', $normalized)) {
            DB::insert($query);

            return 'Insert executed successfully.';
        }

        if (preg_match('/^UPDATE\b/i', $normalized)) {
            $affected = DB::update($query);

            return "Update executed. Rows affected: {$affected}";
        }

        if (preg_match('/^DELETE\b/i', $normalized)) {
            $affected = DB::delete($query);

            return "Delete executed. Rows affected: {$affected}";
        }

        $result = DB::statement($query);

        return 'Statement executed. Success: ' . ($result ? 'true' : 'false');
    }

    private function formatQueryResults(array $results): string
    {
        $rows = array_map(fn ($row) => (array) $row, $results);

        if (empty($rows)) {
            return '(empty)';
        }

        $columns  = array_keys($rows[0]);
        $widths   = [];

        foreach ($columns as $col) {
            $widths[$col] = mb_strlen((string) $col);
        }

        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $val = $row[$col] ?? '';
                $len = mb_strlen($this->castCellValue($val));
                if ($len > $widths[$col]) {
                    $widths[$col] = min($len, 60);
                }
            }
        }

        $header    = '| ' . implode(' | ', array_map(fn ($c) => str_pad($c, $widths[$c]), $columns)) . ' |';
        $separator = '|-' . implode('-|-', array_map(fn ($c) => str_repeat('-', $widths[$c]), $columns)) . '-|';

        $lines = [$header, $separator];

        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $col) {
                $val = $this->castCellValue($row[$col] ?? '');
                $cells[] = str_pad(mb_substr($val, 0, $widths[$col]), $widths[$col]);
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines);
    }

    private function castCellValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function runArtisan(array $arguments): string
    {
        $command = trim($arguments['command'] ?? '');

        if (empty($command)) {
            return 'Error: the "command" argument is required.';
        }

        $commandName = strtok($command, ' ');

        if (in_array($commandName, self::BLOCKED_ARTISAN_COMMANDS, true)) {
            return "Error: '{$commandName}' is blocked. Long-running and interactive commands cannot run via MCP.";
        }

        try {
            $exitCode = Artisan::call($command);
            $output   = Artisan::output();

            return "Exit code: {$exitCode}\n\n" . ($output ?: '(no output)');
        } catch (\Throwable $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }
}
