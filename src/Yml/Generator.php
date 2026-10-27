<?php

namespace Cipi\Agent\Yml;

use Cipi\Agent\Support\AppHome;
use Cipi\Agent\Support\Environment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/**
 * Infers a cipi.yml from the Laravel application itself.
 *
 * `cipi yml generate <app>` on the server prints the configuration the server
 * already has. This is the other direction: a developer who has never run
 * the app on Cipi gets a starting point derived from composer.json, the
 * framework configuration, the scheduler and .env.example — Horizon or
 * plain queue workers, Reverb, Scout, the /up health route, the PHP version,
 * the extra databases in the app's namespace and the third-party variables
 * the .env must carry. The output is fed back through the validator before
 * it is returned, so it is never a file this Cipi would reject.
 */
final class Generator
{
    /** Variables Cipi writes or manages itself: never a candidate for env.required. */
    public const MANAGED_ENV_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE', 'APP_LOCALE',
        'APP_FALLBACK_LOCALE', 'APP_FAKER_LOCALE', 'APP_MAINTENANCE_DRIVER', 'APP_MAINTENANCE_STORE',
        'PHP_CLI_SERVER_WORKERS', 'BCRYPT_ROUNDS', 'LOG_CHANNEL', 'LOG_STACK', 'LOG_DEPRECATIONS_CHANNEL',
        'LOG_LEVEL', 'LOG_DAILY_DAYS', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        'DB_PASSWORD', 'DB_URL', 'SESSION_DRIVER', 'SESSION_LIFETIME', 'SESSION_ENCRYPT', 'SESSION_PATH',
        'SESSION_DOMAIN', 'BROADCAST_CONNECTION', 'FILESYSTEM_DISK', 'QUEUE_CONNECTION', 'CACHE_STORE',
        'CACHE_PREFIX', 'MEMCACHED_HOST', 'REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_PORT',
        'MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'VITE_APP_NAME', 'OCTANE_SERVER', 'OCTANE_HTTPS',
        'CIPI_WEBHOOK_TOKEN', 'CIPI_APP_USER', 'CIPI_PHP_VERSION', 'CIPI_DEPLOY_SCRIPT', 'CIPI_DEPLOY_BRANCH',
        'CIPI_ROUTE_PREFIX', 'CIPI_LOG_CHANNEL', 'CIPI_HEALTH_CHECK', 'CIPI_HEALTH_TOKEN', 'CIPI_MCP',
        'CIPI_MCP_TOKEN', 'CIPI_ANONYMIZER', 'CIPI_ANONYMIZER_TOKEN', 'CIPI_HOME', 'CIPI_BASE_URL', 'CIPI_TOKEN',
        'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME',
        'REVERB_SERVER_HOST', 'REVERB_SERVER_PORT', 'VITE_REVERB_APP_KEY', 'VITE_REVERB_HOST', 'VITE_REVERB_PORT',
        'VITE_REVERB_SCHEME', 'SCOUT_DRIVER', 'SCOUT_PREFIX', 'SCOUT_QUEUE', 'MEILISEARCH_HOST', 'MEILISEARCH_KEY',
    ];

    /**
     * @return array{yaml: string, result: Result, facts: array<string, mixed>}
     */
    public static function generate(?string $app = null): array
    {
        $app = $app ?? AppHome::user();
        $facts = self::facts($app);
        $yaml = self::render($app, $facts);
        $result = Validator::validate($yaml, $app);

        return ['yaml' => $yaml, 'result' => $result, 'facts' => $facts];
    }

    /**
     * What the generator could learn about the app.
     *
     * @return array<string, mixed>
     */
    public static function facts(?string $app): array
    {
        $domain = self::domain();
        $php = (string) config('cipi.php_version', PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
        $queueConnection = (string) config('queue.default', 'sync');
        $queueDefault = (string) config("queue.connections.{$queueConnection}.queue", 'default');

        return [
            'app' => $app,
            'domain' => $domain,
            'php' => in_array($php, Validator::PHP_VERSIONS, true) ? $php : null,
            'horizon' => self::hasPackage('laravel/horizon') || class_exists(\Laravel\Horizon\Horizon::class),
            'reverb' => self::hasPackage('laravel/reverb') || config('broadcasting.default') === 'reverb',
            'queue_connection' => $queueConnection,
            'queue' => $queueConnection === 'sync' ? null : ($queueDefault !== '' ? $queueDefault : 'default'),
            'horizon_queues' => self::horizonQueues(),
            'schedule' => self::scheduledEvents(),
            'scout_meilisearch' => config('scout.driver') === 'meilisearch',
            'health_url' => self::healthUrl($domain),
            'databases' => self::databases($app),
            'env_required' => self::envRequired(),
            'npm_build' => self::hasNpmScript('build'),
            'octane' => self::hasPackage('laravel/octane') || (string) config('octane.server', '') !== '',
            'branch' => self::branch(),
            'env' => Environment::label(),
        ];
    }

    /** The branch the app deploys (CIPI_DEPLOY_BRANCH), when it is a usable branch name. */
    private static function branch(): ?string
    {
        $branch = config('cipi.deploy_branch');

        return is_string($branch) && preg_match(Validator::GIT_BRANCH_RE, $branch) && ! str_contains($branch, '..') ? $branch : null;
    }

    /**
     * @param  array<string, mixed>  $f
     */
    public static function render(?string $app, array $f): string
    {
        $appName = $app ?? 'myapp';
        $domain = $f['domain'] ?? 'example.com';
        $q = fn (string $v): string => self::quote($v);
        $o = [];

        $o[] = "# cipi.yml for '{$appName}' ({$domain}) — generated on ".date('Y-m-d H:i:s');
        $o[] = '# by: php artisan cipi:yml generate (cipi/agent), from the Laravel application';
        $o[] = '#';
        $o[] = '# Review it, commit it at the root of the repository and check it on the';
        $o[] = "# server with:  cipi yml plan {$appName}";
        $o[] = '# The server is authoritative for what is configured today:';
        $o[] = "#   cipi yml generate {$appName} > cipi.yml";
        $o[] = '#';
        $o[] = '# Not covered here, on purpose: the primary domain, the app\'s own database,';
        $o[] = '# SSL certificates and anything else that is not safe to drive from a file';
        $o[] = '# living in the repository.';
        $o[] = '';
        $o[] = 'version: 1';
        $o[] = '';
        $o[] = 'app:';
        if ($f['php'] !== null) {
            $o[] = '  # PHP version assigned to the app (CIPI_PHP_VERSION). Must be installed on the server.';
            $o[] = '  php: '.$q($f['php']);
        } else {
            $o[] = '  # 8.3, 8.4 or 8.5 — must already be installed (cipi php install 8.5)';
            $o[] = '  # php: "8.4"';
        }
        $o[] = '';
        $o[] = '  # The declared list replaces the current aliases: removing one here';
        $o[] = '  # removes it from the server. Uncomment to declare some:';
        $o[] = '  # aliases:';
        $o[] = '  #   - "www.'.$domain.'"';
        $o[] = '';
        $o[] = '  # Per-app php.ini overrides. Uncomment to pin some:';
        $o[] = '  # ini:';
        $o[] = '  #   upload_max_filesize: 50M';
        $o[] = '  #   post_max_size: 60M';
        $o[] = '';
        $o[] = '  # Per-app limits (cipi app limits). Defaults: memory_limit 256M,';
        $o[] = '  # fpm_max_children 5, octane_workers 2, worker_procs 1.';
        $o[] = '  # limits:';
        $o[] = '  #   memory_limit: 512M';
        if ($f['octane']) {
            $o[] = '  #   octane_workers: 4';
        } else {
            $o[] = '  #   fpm_max_children: 10';
        }
        $o[] = '';
        $o[] = '  # to-root (www → apex), from-root (apex → www) or none.';
        $o[] = '  # www: to-root';
        $o[] = '';
        $o[] = '  basic_auth: false     # or a list of users — see: php artisan cipi:yml example';

        $o[] = '';
        if ($f['databases'] !== []) {
            $o[] = "# Extra databases owned by this app (connections in config/database.php). The";
            $o[] = "# app's own database ('{$appName}') is created with the app and is not managed here.";
            $o[] = 'databases:';
            foreach ($f['databases'] as $db) {
                $o[] = '  - name: '.$q($db['name']);
                $o[] = '    engine: '.$db['engine'];
            }
        } else {
            $o[] = "# No extra databases. A declared database must be named '{$appName}' or";
            $o[] = "# '{$appName}_*' — nothing outside this app's namespace is accepted.";
            $o[] = '# databases:';
            $o[] = "#   - name: {$appName}_reporting";
        }

        $o[] = '';
        $o[] = 'workers:';
        if ($f['horizon']) {
            $o[] = '  # laravel/horizon is installed: Horizon replaces the queue:work workers.';
            $o[] = '  horizon: true';
            if ($f['horizon_queues'] !== []) {
                $o[] = '  # Horizon supervisors handle: '.implode(', ', $f['horizon_queues']);
            }
        } elseif ($f['queue'] !== null) {
            $o[] = '  horizon: false';
            $o[] = "  # QUEUE_CONNECTION={$f['queue_connection']} — one worker per queue the app uses.";
            $o[] = '  queues:';
            $o[] = '    - queue: '.$q($f['queue']);
            $o[] = '      processes: 1';
            $o[] = '      # tries: 3';
            $o[] = '      # timeout: 3600';
        } else {
            $o[] = '  horizon: false';
            $o[] = '  # QUEUE_CONNECTION=sync — no queue workers needed. Uncomment when you add a queue:';
            $o[] = '  # queues:';
            $o[] = '  #   - queue: default';
            $o[] = '  #     processes: 2';
        }
        if ($f['reverb']) {
            $o[] = '  # laravel/reverb is installed — nginx proxies /app and /apps to it on this domain.';
            $o[] = '  reverb: true';
        } else {
            $o[] = '  reverb: false          # true adds a Reverb WebSocket server';
        }

        $o[] = '';
        if ($f['schedule'] > 0) {
            $o[] = "# {$f['schedule']} scheduled task(s) found — the Laravel scheduler must run.";
            $o[] = 'schedule: true';
        } else {
            $o[] = '# No scheduled tasks found (routes/console.php). Set true once you add some.';
            $o[] = 'schedule: false';
        }

        $o[] = '';
        if ($f['scout_meilisearch']) {
            $o[] = '# SCOUT_DRIVER=meilisearch — Cipi provisions a scoped key and the SCOUT_* /';
            $o[] = '# MEILISEARCH_* variables (root installs the engine: cipi search install).';
            $o[] = 'search: true';
        } else {
            $o[] = '# Meilisearch for Laravel Scout (needs: cipi search install).';
            $o[] = 'search: false';
        }

        $o[] = '';
        $o[] = '# Deploy recipe options (cipi app deploy-config) and post-deploy steps.';
        $o[] = '# Only declared keys are reconciled.';
        $o[] = 'deploy:';
        $o[] = '  # The git branch the app deploys (cipi app edit --branch). With one file per';
        $o[] = '  # environment (cipi-staging.yml, cipi-production.yml) each can name its own.';
        $o[] = '  # branch: '.$q($f['branch'] ?? 'main');
        $o[] = '  # keep_releases: 5';
        $o[] = '  # migrate: true';
        $o[] = '  # optimize: true';
        $o[] = '  # storage_link: true';
        $o[] = '  # queue_restart: true';
        if ($f['horizon']) {
            $o[] = '  # horizon_terminate: true';
        }
        $o[] = '  # snapshot: true        # database snapshot before each deploy';
        $o[] = '  # Post-deploy commands (run after every successful deploy, from the release):';
        $o[] = '  # post:';
        $o[] = '  #   - artisan cache:clear';
        if ($f['npm_build']) {
            $o[] = '  #   - npm run build       # package.json has a "build" script';
        }
        if ($f['scout_meilisearch']) {
            $o[] = '  #   - artisan scout:import --force';
        }

        $o[] = '';
        $o[] = '# Force the HTTP → HTTPS redirect. Needs a certificate first (cipi ssl install).';
        $o[] = '# ssl:';
        $o[] = '#   force_https: true';

        $o[] = '';
        if ($f['env_required'] !== []) {
            $o[] = '# Names (never values) the .env on the server must carry — taken from the';
            $o[] = '# credential-looking keys of .env.example. The plan is blocked while one is';
            $o[] = '# missing or empty, so code that expects them is never deployed blind.';
            $o[] = 'env:';
            $o[] = '  required:';
            foreach ($f['env_required'] as $key) {
                $o[] = '    - '.$key;
            }
        } else {
            $o[] = '# Names (never values) the .env on the server must carry — the plan is';
            $o[] = '# blocked while one is missing or empty. Uncomment and list yours:';
            $o[] = '# env:';
            $o[] = '#   required: [ STRIPE_KEY, MAIL_HOST ]';
        }

        $o[] = '';
        $o[] = '# Scheduled commands, through the same runners as deploy.post. The declared';
        $o[] = "# list replaces the '# cipi-yml' crontab entries.";
        $o[] = '# crons:';
        $o[] = '#   - every: 30m';
        $o[] = '#     run: artisan queue:prune-batches';

        $o[] = '';
        if ($f['health_url'] !== null) {
            $o[] = '# HTTP healthcheck on the framework /up route. Probed every 5 minutes and';
            $o[] = '# right after every deploy; the URL must be one of this app\'s own domains.';
            $o[] = 'health:';
            $o[] = '  url: '.$q($f['health_url']);
            $o[] = '  expect: 200';
            $o[] = '  # grace: 8';
            $o[] = '  # rollback_on_unhealthy: true   # the code symlink only — migrations are NOT undone';
        } else {
            $o[] = '# No health route detected. Uncomment to probe one (every 5 minutes and after each deploy):';
            $o[] = '# health:';
            $o[] = '#   url: "https://'.$domain.'/up"';
            $o[] = '#   expect: 200';
        }

        $o[] = '';
        $o[] = '# Whole-app redirect, path redirects and prefix proxies — see: php artisan cipi:yml example';
        $o[] = '# redirect:';
        $o[] = '#   to: "https://new.example.com"';
        $o[] = '# redirects:';
        $o[] = '#   - from: /old-page';
        $o[] = '#     to: /new-page';
        $o[] = '# proxies:';
        $o[] = '#   - prefix: /api/';
        $o[] = '#     upstream: "http://127.0.0.1:3000"';
        $o[] = '#     strip_prefix: true';

        $o[] = '';
        $o[] = "# Backup strategy. Profile names must be '{$appName}' or '{$appName}-*'.";
        $o[] = '# backup:';
        $o[] = '#   profiles:';
        $o[] = "#     - name: {$appName}-db";
        $o[] = '#       scope: db';
        $o[] = '#       databases: ['.$q($appName).', "'.$appName.'_*"]';
        $o[] = '#       exclude_tables: ["*.jobs", "*.telescope_*"]';
        $o[] = '#       every: 30m';
        $o[] = '#       keep: 48';
        $o[] = '#       destinations: [local]';
        $o[] = '';

        return implode("\n", $o);
    }

    // ── facts ────────────────────────────────────────────────

    private static function domain(): string
    {
        $host = parse_url((string) config('app.url', ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' && $host !== 'localhost' ? $host : 'example.com';
    }

    private static function healthUrl(string $domain): ?string
    {
        if ($domain === 'example.com') {
            return null;
        }
        try {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if (trim($route->uri(), '/') === 'up' && in_array('GET', $route->methods(), true)) {
                    return "https://{$domain}/up";
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function scheduledEvents(): int
    {
        try {
            if (! app()->bound(Schedule::class)) {
                return 0;
            }

            return count(app(Schedule::class)->events());
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<string> */
    private static function horizonQueues(): array
    {
        $queues = [];
        $env = (string) config('horizon.env', app()->environment());
        $sets = [config('horizon.defaults', []), config("horizon.environments.{$env}", []), config('horizon.environments.production', [])];
        foreach ($sets as $supervisors) {
            if (! is_array($supervisors)) {
                continue;
            }
            foreach ($supervisors as $supervisor) {
                foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                    if (is_string($queue) && ! in_array($queue, $queues, true)) {
                        $queues[] = $queue;
                    }
                }
            }
        }

        return $queues;
    }

    /** @return list<array{name: string, engine: string}> */
    private static function databases(?string $app): array
    {
        if ($app === null) {
            return [];
        }
        $default = (string) config('database.default', '');
        $defaultDb = (string) config("database.connections.{$default}.database", '');
        $out = [];
        foreach ((array) config('database.connections', []) as $connection) {
            if (! is_array($connection)) {
                continue;
            }
            $driver = $connection['driver'] ?? '';
            $engine = match ($driver) {
                'mysql', 'mariadb' => 'mariadb',
                'pgsql' => 'pgsql',
                default => null,
            };
            $name = $connection['database'] ?? null;
            if ($engine === null || ! is_string($name) || $name === '' || $name === $defaultDb) {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) || ! str_starts_with($name, $app.'_')) {
                continue;
            }
            if (! in_array($name, array_column($out, 'name'), true)) {
                $out[] = ['name' => $name, 'engine' => $engine];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function envRequired(): array
    {
        $example = base_path('.env.example');
        if (! is_file($example)) {
            return [];
        }
        $keys = [];
        foreach (file($example, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            $key = trim(explode('=', $line, 2)[0]);
            if (! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key) || in_array($key, self::MANAGED_ENV_KEYS, true)) {
                continue;
            }
            if (preg_match('/(_KEY|_SECRET|_TOKEN|_PASSWORD|_DSN|_CLIENT_ID|_WEBHOOK_SECRET)$/', $key) && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return array_slice($keys, 0, 100);
    }

    private static function hasPackage(string $name): bool
    {
        $lock = base_path('composer.lock');
        if (is_file($lock)) {
            $data = json_decode((string) file_get_contents($lock), true);
            foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
                if (($pkg['name'] ?? null) === $name) {
                    return true;
                }
            }

            return false;
        }
        $json = base_path('composer.json');
        if (is_file($json)) {
            $data = json_decode((string) file_get_contents($json), true);

            return isset($data['require'][$name]);
        }

        return false;
    }

    private static function hasNpmScript(string $script): bool
    {
        $json = base_path('package.json');
        if (! is_file($json)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($json), true);

        return isset($data['scripts'][$script]);
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
