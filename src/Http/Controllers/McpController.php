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
                    . "'deploy' to trigger a deployment, 'logs' to read recent error logs, "
                    . "and 'artisan' to run Artisan commands.",
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
                        'description' => 'Read the last N lines from the Laravel application log file (storage/logs/laravel.log).',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'lines' => [
                                    'type'        => 'integer',
                                    'description' => 'Number of log lines to return. Default: 50. Max: 500.',
                                    'default'     => 50,
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
        $lines   = min((int) ($arguments['lines'] ?? 50), 500);
        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath)) {
            return "Log file not found at: {$logPath}";
        }

        if (filesize($logPath) === 0) {
            return '(log file is empty)';
        }

        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        $startLine  = max(0, $totalLines - $lines);

        $file->seek($startLine);
        $content = [];

        while (! $file->eof()) {
            $content[] = $file->fgets();
        }

        return implode('', $content) ?: '(no content)';
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
