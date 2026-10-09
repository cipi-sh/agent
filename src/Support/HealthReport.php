<?php

namespace Cipi\Agent\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * The health report shared by GET /cipi/health and the MCP `health` tool.
 */
final class HealthReport
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $checks = [
            'app' => self::app(),
            'database' => self::database(),
            'cache' => self::cache(),
            'queue' => self::queue(),
            'storage' => self::storage(),
            'deploy' => self::deploy(),
        ];

        $healthy = collect($checks)->every(fn (array $c) => $c['ok']);

        return [
            'status' => $healthy ? 'healthy' : 'degraded',
            'app_user' => AppHome::user(),
            'php' => config('cipi.php_version', PHP_VERSION),
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function app(): array
    {
        $maintenance = false;
        try {
            $maintenance = app()->isDownForMaintenance();
        } catch (\Throwable) {
        }

        return [
            'ok' => true,
            'version' => config('app.version', config('app.name', 'unknown')),
            'debug' => (bool) config('app.debug'),
            'maintenance' => $maintenance,
        ];
    }

    /** @return array<string, mixed> */
    public static function database(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'ok' => true,
                'driver' => DB::connection()->getDriverName(),
                'database' => DB::connection()->getDatabaseName(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public static function cache(): array
    {
        try {
            $key = 'cipi_health_'.md5((string) AppHome::user());
            Cache::put($key, true, 10);
            $hit = Cache::get($key) === true;
            Cache::forget($key);

            return ['ok' => $hit, 'driver' => config('cache.default', 'file')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public static function queue(): array
    {
        try {
            $check = [
                'ok' => true,
                'connection' => config('queue.default', 'sync'),
                'pending_jobs' => Queue::size(),
            ];

            try {
                $table = config('queue.failed.table', 'failed_jobs');
                if ($table && Schema::hasTable($table)) {
                    $check['failed_jobs'] = DB::table($table)->count();
                }
            } catch (\Throwable) {
                // failed-jobs table is optional
            }

            return $check;
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public static function storage(): array
    {
        $paths = [
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];
        $notWritable = [];
        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $notWritable[] = $label;
            }
        }

        $check = ['ok' => $notWritable === []];
        if ($notWritable !== []) {
            $check['not_writable'] = $notWritable;
        }

        if (($base = AppHome::base()) !== null && is_dir($base)) {
            $free = @disk_free_space($base);
            $total = @disk_total_space($base);
            if ($free !== false && $total !== false && $total > 0) {
                $check['disk_free_gb'] = round($free / 1024 ** 3, 2);
                $check['disk_used_percent'] = (int) round(100 - ($free / $total) * 100);
            }
        }

        return $check;
    }

    /**
     * Deploy metadata is informational: an app that is not deployed by Cipi
     * (local development, another host) is not unhealthy for lacking it, and a
     * failed deploy leaves the previous release serving.
     *
     * @return array<string, mixed>
     */
    public static function deploy(): array
    {
        $commit = DeployInfo::commit();
        $check = [
            'ok' => true,
            'release' => DeployInfo::currentRelease(),
            'commit' => $commit['commit'],
            'short_commit' => $commit['commit'] !== null ? substr($commit['commit'], 0, 7) : null,
            'commit_source' => $commit['source'],
            'in_progress' => DeployTrigger::inProgress(),
            'pending' => DeployTrigger::pending(),
        ];

        if (($last = DeployInfo::lastDeploy()) !== null) {
            $check['last_deploy'] = $last;
            if ($last['status'] === 'failed') {
                // The previous release is still live, so the app is not down;
                // the failure is flagged for humans and the MCP tools instead.
                $check['attention'] = 'The last automatic deploy failed — see '.$last['log'];
            }
        }

        if ($commit['commit'] === null && AppHome::user() === null) {
            $check['note'] = 'CIPI_APP_USER is not configured; deploy metadata is unavailable.';
        }

        return $check;
    }
}
