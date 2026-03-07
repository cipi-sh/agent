<?php

namespace Cipi\Agent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'app' => $this->checkApp(),
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'deploy' => $this->checkDeploy(),
        ];

        $allHealthy = collect($checks)->every(fn ($check) => $check['ok']);

        return response()->json([
            'status' => $allHealthy ? 'healthy' : 'degraded',
            'app_user' => config('cipi.app_user'),
            'php' => config('cipi.php_version', PHP_VERSION),
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    protected function checkApp(): array
    {
        return [
            'ok' => true,
            'version' => config('app.version', config('app.name', 'unknown')),
            'debug' => config('app.debug'),
        ];
    }

    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            $dbName = DB::connection()->getDatabaseName();
            return ['ok' => true, 'database' => $dbName];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function checkCache(): array
    {
        try {
            $key = 'cipi_health_' . md5(config('cipi.app_user'));
            Cache::put($key, true, 10);
            $result = Cache::get($key);
            Cache::forget($key);
            return ['ok' => $result === true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function checkQueue(): array
    {
        try {
            $connection = config('queue.default', 'sync');
            $size = Queue::size();
            return ['ok' => true, 'connection' => $connection, 'pending_jobs' => $size];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function checkDeploy(): array
    {
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            return ['ok' => false, 'error' => 'App user not configured'];
        }

        $commitHash = null;
        $deployInfo = [];

        try {
            // Try multiple sources for deploy information

            // 1. Cipi deploy info file
            $deployInfoPath = "/home/{$appUser}/.cipi/deploy.json";
            if (file_exists($deployInfoPath)) {
                $deployData = json_decode(file_get_contents($deployInfoPath), true);
                if ($deployData && isset($deployData['commit'])) {
                    $commitHash = $deployData['commit'];
                    $deployInfo = $deployData;
                }
            }

            // 2. Last commit file
            if (!$commitHash) {
                $lastCommitPath = "/home/{$appUser}/.cipi/last_commit";
                if (file_exists($lastCommitPath)) {
                    $commitHash = trim(file_get_contents($lastCommitPath));
                }
            }

            // 3. Deploy log (last successful deploy)
            if (!$commitHash) {
                $deployLogPath = "/home/{$appUser}/logs/deploy.log";
                if (file_exists($deployLogPath)) {
                    $lines = file($deployLogPath);
                    foreach (array_reverse($lines) as $line) {
                        // Look for commit hash patterns in deploy log
                        if (preg_match('/commit[:\s]+([a-f0-9]{7,40})/i', $line, $matches)) {
                            $commitHash = $matches[1];
                            break;
                        }
                        // Also check for common deploy success messages with commit
                        if (preg_match('/([a-f0-9]{7,40})/', $line, $matches) && strpos($line, 'success') !== false) {
                            $commitHash = $matches[1];
                            break;
                        }
                    }
                }
            }

            // 4. Git repository HEAD
            if (!$commitHash) {
                $gitHeadPath = base_path('.git/HEAD');
                if (file_exists($gitHeadPath)) {
                    $headContent = trim(file_get_contents($gitHeadPath));
                    if (preg_match('/ref: (.+)/', $headContent, $matches)) {
                        $refPath = base_path('.git/' . $matches[1]);
                        if (file_exists($refPath)) {
                            $commitHash = trim(file_get_contents($refPath));
                        }
                    } elseif (preg_match('/([a-f0-9]{40})/', $headContent, $matches)) {
                        $commitHash = $matches[1];
                    }
                }
            }

            // 5. Try git command as last resort
            if (!$commitHash) {
                try {
                    $gitCommand = 'git rev-parse HEAD 2>/dev/null';
                    $output = shell_exec($gitCommand);
                    if ($output) {
                        $commitHash = trim($output);
                    }
                } catch (\Throwable $e) {
                    // Git command failed, continue
                }
            }

            if ($commitHash) {
                // Validate commit hash format
                if (preg_match('/^[a-f0-9]{7,40}$/', $commitHash)) {
                    return [
                        'ok' => true,
                        'commit' => $commitHash,
                        'short_commit' => substr($commitHash, 0, 7),
                        'info' => $deployInfo,
                    ];
                }
            }

            return ['ok' => false, 'error' => 'No deploy information found'];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
