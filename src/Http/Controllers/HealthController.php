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
}
