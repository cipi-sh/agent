<?php

namespace Cipi\Agent\Yml;

/**
 * Validates a parsed cipi.yml against Cipi's schema.
 *
 * Port of the validator shipped with the Cipi CLI (lib/yml.sh). Everything is
 * fail-closed: an unknown key, a value outside its allowed set or a database
 * outside the app's namespace is an error, never something skipped. Error
 * strings match the server's so a developer sees the same message locally,
 * in CI and on the server.
 *
 * The normalized document (`data`) carries the defaults the server applies
 * (e.g. `deploy.post_on_failure: warn`, `health.expect: 200`).
 */
final class Validator
{
    public const PHP_VERSIONS = ['8.3', '8.4', '8.5'];

    public const ENGINES = ['mariadb', 'pgsql'];

    public const SCOPES = ['all', 'files', 'db'];

    public const DESTINATIONS = ['local', 's3'];

    public const INI_KEYS = [
        'memory_limit', 'upload_max_filesize', 'post_max_size',
        'max_execution_time', 'max_input_time', 'max_input_vars',
        'max_file_uploads', 'default_socket_timeout', 'date.timezone',
        'display_errors', 'log_errors', 'output_buffering',
        'zlib.output_compression', 'session.gc_maxlifetime',
        'realpath_cache_size', 'realpath_cache_ttl', 'expose_php',
        'opcache.enable', 'opcache.enable_cli', 'opcache.memory_consumption',
        'opcache.interned_strings_buffer', 'opcache.max_accelerated_files',
        'opcache.validate_timestamps', 'opcache.revalidate_freq',
        'opcache.jit', 'opcache.jit_buffer_size',
    ];

    public const POST_RUNNERS = ['artisan', 'npm', 'npx', 'yarn', 'pnpm', 'composer', 'php', 'node'];

    public const NPM_FORBIDDEN = ['explore', 'init', 'login', 'adduser', 'edit'];

    public const COMPOSER_FORBIDDEN = ['shell', 'browse', 'fund'];

    public const REDIRECT_CODES = [301, 302, 307, 308];

    public const WWW_MODES = ['from-root', 'none', 'to-root'];

    public const NODE_FRAMEWORKS = ['astro', 'next', 'nuxt', 'remix', 'sveltekit', 'vite'];

    public const NODE_MODES = ['spa', 'static', 'ssr'];

    public const NODE_RUNNERS = ['node', 'npm', 'npx', 'pnpm', 'yarn', 'bun'];

    public const LIMIT_BOUNDS = ['fpm_max_children' => [1, 50], 'octane_workers' => [1, 16], 'worker_procs' => [1, 20]];

    public const TOP_LEVEL_KEYS = ['version', 'app', 'databases', 'workers', 'backup', 'schedule', 'health', 'deploy',
        'redirect', 'redirects', 'proxies', 'search', 'node', 'ssl', 'env', 'crons'];

    private const MAX_POST_STEPS = 20;

    private const MAX_POST_ARGS = 32;

    private const MAX_REDIRECTS = 100;

    private const MAX_PROXIES = 20;

    private const MAX_BASICAUTH_USERS = 20;

    private const MAX_EXTRA_ARTISAN = 20;

    private const MAX_ENV_REQUIRED = 100;

    private const MAX_CRONS = 20;

    private const KEEP_RELEASES_MIN = 1;

    private const KEEP_RELEASES_MAX = 20;

    private const DOMAIN_RE = '/^(\*\.)?[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/';

    private const DB_NAME_RE = '/^[a-z][a-z0-9_]{1,63}$/';

    private const QUEUE_RE = '/^[a-zA-Z0-9_-]{1,64}$/';

    private const PROFILE_RE = '/^[a-z][a-z0-9-]{1,31}$/';

    private const EVERY_RE = '/^([0-9]+)([mhd])$/';

    private const CRON_RE = '/^[0-9*\/,\s-]+$/';

    private const GLOB_RE = '/^[a-zA-Z0-9_.*?\[\]-]{1,64}$/';

    private const TABLE_GLOB_RE = '/^[a-zA-Z0-9_.*?\[\]-]{1,128}$/';

    private const URL_RE = '/^https?:\/\/[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?(\/[A-Za-z0-9._~\/-]{0,200})?$/';

    private const ARTISAN_CMD_RE = '/^[a-zA-Z0-9:_-]+$/';

    private const POST_ARG_RE = '/^[a-zA-Z0-9_@.\/:=+-]{1,256}$/';

    private const REL_SCRIPT_RE = '/^[a-zA-Z0-9][a-zA-Z0-9_.\/-]{0,200}$/';

    private const ROUTE_PATH_RE = '/^\/[A-Za-z0-9._~\/+@:,=-]*$/';

    private const ROUTE_TARGET_PATH_RE = '/^\/[A-Za-z0-9._~%\/+@:,=-]*([?][A-Za-z0-9._~%\/+@:,=&-]*)?$/';

    private const ROUTE_URL_RE = '/^https?:\/\/[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?(\/[A-Za-z0-9._~%\/+@:,=&?#!-]*)?$/';

    private const ROUTE_UPSTREAM_RE = '/^https?:\/\/[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?(\/[A-Za-z0-9._~%\/+-]*)?$/';

    private const BASICAUTH_USER_RE = '/^[A-Za-z0-9._-]{1,64}$/';

    private const BCRYPT_RE = '/^\$2[aby]\$([0-9]{2})\$[.\/A-Za-z0-9]{53}$/';

    private const SHA512CRYPT_RE = '/^\$6\$(rounds=[0-9]{4,9}\$)?[.\/A-Za-z0-9]{1,16}\$[.\/A-Za-z0-9]{86}$/';

    private const NODE_BUILD_RE = '/^[a-zA-Z0-9_.\/= :&;\'"-]+$/';

    private const NODE_ARG_RE = '/^[A-Za-z0-9@._\/:=+-]+$/';

    private const NODE_OUTPUT_RE = '/^[A-Za-z0-9_.][A-Za-z0-9._\/-]{0,120}$/';

    private const NODE_HEALTH_RE = '/^\/[A-Za-z0-9._~\/-]{0,200}$/';

    private const MEMORY_LIMIT_RE = '/^[0-9]{1,5}[MmGg]?$/';

    private const ENV_KEY_RE = '/^[A-Z][A-Z0-9_]{0,63}$/';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  string|null  $app  The app (Linux user) the file belongs to. Databases and
     *                            backup profiles must live in its namespace. Without it the
     *                            namespace checks are skipped and a warning is added.
     */
    public function __construct(private readonly ?string $app = null) {}

    /**
     * Parse + validate a file's raw contents.
     */
    public static function validate(string $text, ?string $app = null, ?string $file = null): Result
    {
        if (strlen($text) > YamlParser::MAX_BYTES) {
            return Result::failure(['file is larger than '.YamlParser::MAX_BYTES.' bytes'], [], $file);
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            return Result::failure(['file is not valid UTF-8'], [], $file);
        }
        if (substr_count($text, "\n") > YamlParser::MAX_LINES) {
            return Result::failure(['file has more than '.YamlParser::MAX_LINES.' lines'], [], $file);
        }

        try {
            $doc = YamlParser::parse($text);
        } catch (YamlError $e) {
            return Result::failure([$e->getMessage()], [], $file);
        }

        $validator = new self($app);
        $data = $validator->run($doc);

        if ($validator->errors !== []) {
            return Result::failure($validator->errors, $validator->warnings, $file);
        }

        return Result::success($data ?? [], $validator->warnings, $file);
    }

    public static function validateFile(string $path, ?string $app = null): Result
    {
        if (! is_file($path) || ! is_readable($path)) {
            return Result::failure(["cannot read {$path}"], [], $path);
        }

        return self::validate((string) file_get_contents($path), $app, $path);
    }

    // ── helpers ──────────────────────────────────────────────

    private function err(string $path, string $msg): void
    {
        $this->errors[] = "{$path}: {$msg}";
    }

    private function warn(string $path, string $msg): void
    {
        $this->warnings[] = "{$path}: {$msg}";
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** @return array<string, mixed>|null */
    private function expectMap(mixed $value, string $path): ?array
    {
        if ($value === null) {
            return [];
        }
        if (! self::isMap($value)) {
            $this->err($path, 'expected a mapping of keys');

            return null;
        }

        return $value;
    }

    /** @return list<mixed>|null */
    private function expectList(mixed $value, string $path): ?array
    {
        if ($value === null) {
            return [];
        }
        if (! self::isList($value)) {
            $this->err($path, 'expected a list');

            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private function unknownKeys(array $value, array $allowed, string $path): void
    {
        $sorted = $allowed;
        sort($sorted, SORT_STRING);
        foreach (array_keys($value) as $k) {
            if (! in_array((string) $k, $allowed, true)) {
                $this->err("{$path}.{$k}", 'unknown key (allowed: '.implode(', ', $sorted).')');
            }
        }
    }

    private function asStr(mixed $value, string $path): ?string
    {
        if (is_bool($value) || $value === null) {
            $this->err($path, 'expected a string');

            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            $this->err($path, 'expected a string');

            return null;
        }

        return $value;
    }

    private function asInt(mixed $value, string $path, int $lo, int $hi): ?int
    {
        if (! is_int($value)) {
            $this->err($path, 'expected a whole number');

            return null;
        }
        if ($value < $lo || $value > $hi) {
            $this->err($path, "must be between {$lo} and {$hi}");

            return null;
        }

        return $value;
    }

    private function asBool(mixed $value, string $path): ?bool
    {
        if (! is_bool($value)) {
            $this->err($path, 'expected true or false');

            return null;
        }

        return $value;
    }

    private static function repr(mixed $value): string
    {
        if (is_string($value)) {
            // Python's repr(): single quotes unless the string contains one and no double quote.
            if (str_contains($value, "'") && ! str_contains($value, '"')) {
                return '"'.$value.'"';
            }

            return "'".$value."'";
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        if ($value === null) {
            return 'None';
        }

        return (string) $value;
    }

    // ── sections ─────────────────────────────────────────────

    /**
     * @param  mixed  $doc
     * @return array<string, mixed>|null
     */
    public function run(mixed $doc): ?array
    {
        if (! is_array($doc) || ($doc !== [] && array_is_list($doc))) {
            $this->err('cipi.yml', 'the file must be a mapping at the top level');

            return null;
        }

        $this->unknownKeys($doc, self::TOP_LEVEL_KEYS, 'cipi.yml');

        if (! array_key_exists('version', $doc) || $doc['version'] === null) {
            $this->err('version', "required — add 'version: 1'");
        } elseif ($doc['version'] !== 1) {
            $this->err('version', 'unsupported version '.self::repr($doc['version']).' (this Cipi understands version 1)');
        }

        if ($this->app === null) {
            $this->warn('cipi.yml', 'app name unknown (CIPI_APP_USER / --app) — database and backup profile namespaces were not checked');
        }

        $out = [];
        if (array_key_exists('app', $doc)) {
            $out['app'] = $this->vApp($doc['app']);
        }
        if (array_key_exists('databases', $doc)) {
            $out['databases'] = $this->vDatabases($doc['databases']);
        }
        if (array_key_exists('workers', $doc)) {
            $out['workers'] = $this->vWorkers($doc['workers']);
        }
        if (array_key_exists('backup', $doc)) {
            $out['backup'] = $this->vBackup($doc['backup']);
        }
        if (array_key_exists('schedule', $doc)) {
            $b = $this->asBool($doc['schedule'], 'schedule');
            if ($b !== null) {
                $out['schedule'] = $b;
            }
        }
        if (array_key_exists('search', $doc)) {
            $b = $this->asBool($doc['search'], 'search');
            if ($b !== null) {
                $out['search'] = $b;
            }
        }
        if (array_key_exists('node', $doc)) {
            $out['node'] = $this->vNode($doc['node']);
        }
        if (array_key_exists('health', $doc)) {
            $out['health'] = $this->vHealth($doc['health']);
        }
        if (array_key_exists('deploy', $doc)) {
            $out['deploy'] = $this->vDeploy($doc['deploy']);
        }
        if (array_key_exists('redirect', $doc)) {
            $out['redirect'] = $this->vRedirect($doc['redirect']);
        }
        if (array_key_exists('redirects', $doc)) {
            $out['redirects'] = $this->vRedirects($doc['redirects']);
        }
        if (array_key_exists('proxies', $doc)) {
            $out['proxies'] = $this->vProxies($doc['proxies']);
        }
        if (array_key_exists('ssl', $doc)) {
            $out['ssl'] = $this->vSsl($doc['ssl']);
        }
        if (array_key_exists('env', $doc)) {
            $out['env'] = $this->vEnv($doc['env']);
        }
        if (array_key_exists('crons', $doc)) {
            $out['crons'] = $this->vCrons($doc['crons']);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vSsl(mixed $node): array
    {
        $m = $this->expectMap($node, 'ssl');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['force_https'], 'ssl');
        $out = [];
        if (array_key_exists('force_https', $m)) {
            $b = $this->asBool($m['force_https'], 'ssl.force_https');
            if ($b !== null) {
                $out['force_https'] = $b;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vEnv(mixed $node): array
    {
        $m = $this->expectMap($node, 'env');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['required'], 'env');
        $out = [];
        if (array_key_exists('required', $m)) {
            $lst = $this->expectList($m['required'], 'env.required');
            if ($lst === null) {
                return $out;
            }
            if (count($lst) > self::MAX_ENV_REQUIRED) {
                $this->err('env.required', 'at most '.self::MAX_ENV_REQUIRED.' variables');

                return $out;
            }
            $keys = [];
            foreach ($lst as $i => $item) {
                $p = "env.required[{$i}]";
                $s = $this->asStr($item, $p);
                if ($s === null) {
                    continue;
                }
                if (! preg_match(self::ENV_KEY_RE, $s)) {
                    $this->err($p, 'invalid variable name '.self::repr($s).' (UPPER_CASE letters, digits and _)');

                    continue;
                }
                if (in_array($s, $keys, true)) {
                    $this->err($p, 'duplicate variable '.self::repr($s));

                    continue;
                }
                $keys[] = $s;
            }
            $out['required'] = $keys;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array{0: string, 1: string}|null ['every'|'cron', value]
     */
    private function vCronSchedule(array $m, string $path): ?array
    {
        if (array_key_exists('every', $m) && array_key_exists('cron', $m)) {
            $this->err($path, "use either 'every' or 'cron', not both");

            return null;
        }
        if (array_key_exists('every', $m)) {
            $s = $this->asStr($m['every'], $path.'.every');
            if ($s === null) {
                return null;
            }
            if (! preg_match(self::EVERY_RE, $s, $mm)) {
                $this->err($path.'.every', 'expected a value like 30m, 6h or 1d');

                return null;
            }
            $num = (int) $mm[1];
            $unit = $mm[2];
            if ($num === 0) {
                $this->err($path.'.every', 'must be greater than zero');

                return null;
            }
            if ($unit === 'm' && ($num > 59 || 60 % $num !== 0)) {
                $this->err($path.'.every', 'minutes must divide 60 evenly (5m, 10m, 15m, 20m, 30m)');

                return null;
            }
            if ($unit === 'h' && ($num > 23 || 24 % $num !== 0)) {
                $this->err($path.'.every', 'hours must divide 24 evenly (1h, 2h, 3h, 4h, 6h, 8h, 12h)');

                return null;
            }
            if ($unit === 'd' && $num > 28) {
                $this->err($path.'.every', 'at most 28 days');

                return null;
            }

            return ['every', $s];
        }
        if (array_key_exists('cron', $m)) {
            $s = $this->asStr($m['cron'], $path.'.cron');
            if ($s === null) {
                return null;
            }
            if (! preg_match(self::CRON_RE, $s) || count(preg_split('/\s+/', trim($s)) ?: []) !== 5) {
                $this->err($path.'.cron', 'expected five cron fields using digits and * / , - only');

                return null;
            }

            return ['cron', $s];
        }
        $this->err($path, "'every' (30m, 6h, 1d) or 'cron' is required");

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function vCrons(mixed $node): array
    {
        $lst = $this->expectList($node, 'crons');
        if ($lst === null) {
            return [];
        }
        if (count($lst) > self::MAX_CRONS) {
            $this->err('crons', 'at most '.self::MAX_CRONS.' scheduled commands');

            return [];
        }
        $out = [];
        foreach ($lst as $i => $item) {
            $p = "crons[{$i}]";
            $m = $this->expectMap($item, $p);
            if ($m === null) {
                continue;
            }
            $this->unknownKeys($m, ['every', 'cron', 'run'], $p);
            $sched = $this->vCronSchedule($m, $p);
            if ($sched === null) {
                continue;
            }
            $run = $m['run'] ?? null;
            if (! is_string($run) || trim($run) === '') {
                $this->err($p.'.run', 'a command string using the deploy.post runners (artisan, npm, composer, php, node, …) is required');

                continue;
            }
            $step = $this->vDeployPostString(trim($run), $p.'.run');
            if ($step === null) {
                continue;
            }
            $out[] = [$sched[0] => $sched[1]] + $step;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vNode(mixed $node): array
    {
        $m = $this->expectMap($node, 'node');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['framework', 'mode', 'version', 'build', 'start', 'output', 'health_path'], 'node');
        $out = [];
        if (array_key_exists('framework', $m)) {
            $fw = $this->asStr($m['framework'], 'node.framework');
            if ($fw !== null) {
                if (! in_array($fw, self::NODE_FRAMEWORKS, true)) {
                    $this->err('node.framework', 'must be one of '.implode(', ', self::NODE_FRAMEWORKS));
                } else {
                    $out['framework'] = $fw;
                }
            }
        }
        if (array_key_exists('mode', $m)) {
            $mode = $this->asStr($m['mode'], 'node.mode');
            if ($mode !== null) {
                if (! in_array($mode, self::NODE_MODES, true)) {
                    $this->err('node.mode', 'must be spa, static or ssr');
                } else {
                    $out['mode'] = $mode;
                }
            }
        }
        if (array_key_exists('version', $m)) {
            $v = $m['version'];
            if (is_bool($v) || ! (is_int($v) || is_string($v)) || ! preg_match('/^[2-9][0-9]$/', (string) $v) || ((int) $v) % 2 !== 0) {
                $this->err('node.version', 'must be an even (LTS) major such as 22 or 24');
            } else {
                $out['version'] = (string) $v;
            }
        }
        if (array_key_exists('build', $m)) {
            $b = $this->asStr($m['build'], 'node.build');
            if ($b !== null) {
                $first = explode(' ', $b)[0];
                $bad = strlen($b) > 200 || strpbrk($b, '|<>`') !== false || str_contains($b, '$(')
                    || ! preg_match(self::NODE_BUILD_RE, $b) || ! in_array($first, self::NODE_RUNNERS, true);
                if ($bad) {
                    $this->err('node.build', 'a command starting with node/npm/npx/pnpm/yarn/bun, no pipes, redirects or substitutions');
                } else {
                    $out['build'] = $b;
                }
            }
        }
        if (array_key_exists('start', $m)) {
            $st = $this->asStr($m['start'], 'node.start');
            if ($st !== null) {
                $words = preg_split('/\s+/', trim($st)) ?: [];
                $words = array_values(array_filter($words, fn ($w) => $w !== ''));
                $ok = count($words) >= 2 && count($words) <= 12 && in_array($words[0], self::NODE_RUNNERS, true) && ! str_contains($st, '..');
                foreach ($words as $w) {
                    if (! preg_match(self::NODE_ARG_RE, $w)) {
                        $ok = false;
                    }
                }
                if (! $ok) {
                    $this->err('node.start', 'a Node runner (node npm npx pnpm yarn bun) and plain arguments — it runs without a shell');
                } else {
                    $out['start'] = $st;
                }
            }
        }
        if (array_key_exists('output', $m)) {
            $o = $this->asStr($m['output'], 'node.output');
            if ($o !== null) {
                $o = rtrim($o, '/');
                $segs = explode('/', $o);
                $bad = ! preg_match(self::NODE_OUTPUT_RE, $o) || str_contains($o, '..') || str_contains($o, '//') || $o === '.';
                foreach ($segs as $sg) {
                    if (in_array($sg, ['.git', '.ssh', 'node_modules'], true) || str_starts_with($sg, '.git') || str_starts_with($sg, '.env')) {
                        $bad = true;
                    }
                }
                if ($bad) {
                    $this->err('node.output', 'a directory inside the repository, e.g. dist — never the root, .git, .env or node_modules');
                } else {
                    $out['output'] = $o;
                }
            }
        }
        if (array_key_exists('health_path', $m)) {
            $h = $this->asStr($m['health_path'], 'node.health_path');
            if ($h !== null) {
                if (! preg_match(self::NODE_HEALTH_RE, $h)) {
                    $this->err('node.health_path', 'must be a path such as / or /api/health');
                } else {
                    $out['health_path'] = $h;
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $m */
    private function vCode(array $m, string $path): ?int
    {
        if (! array_key_exists('code', $m)) {
            return 301;
        }
        $v = $m['code'];
        if (! is_int($v) || ! in_array($v, self::REDIRECT_CODES, true)) {
            $this->err($path.'.code', 'must be 301, 302, 307 or 308');

            return null;
        }

        return $v;
    }

    /** @return array<string, mixed> */
    private function vRedirect(mixed $node): array
    {
        $m = $this->expectMap($node, 'redirect');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['enabled', 'to', 'code', 'keep_path'], 'redirect');
        $enabled = true;
        if (array_key_exists('enabled', $m)) {
            $b = $this->asBool($m['enabled'], 'redirect.enabled');
            if ($b === null) {
                return [];
            }
            $enabled = $b;
        }
        if (! array_key_exists('to', $m)) {
            if ($enabled) {
                $this->err('redirect', "'to' is required (or use 'enabled: false' to remove the app redirect)");

                return [];
            }
            foreach (array_keys($m) as $k) {
                if ($k !== 'enabled') {
                    $this->err('redirect.'.$k, "cannot be set without 'to'");
                }
            }

            return ['enabled' => false];
        }
        $to = $this->asStr($m['to'], 'redirect.to');
        if ($to === null) {
            return [];
        }
        if (! preg_match(self::ROUTE_URL_RE, $to)) {
            $this->err('redirect.to', 'must be an http:// or https:// URL');

            return [];
        }
        $code = $this->vCode($m, 'redirect');
        $keep = true;
        if (array_key_exists('keep_path', $m)) {
            $keep = $this->asBool($m['keep_path'], 'redirect.keep_path');
        }
        if ($code === null || $keep === null) {
            return [];
        }

        return ['enabled' => $enabled, 'to' => $to, 'code' => $code, 'keep_path' => $keep];
    }

    /** @return list<array<string, mixed>> */
    private function vRedirects(mixed $node): array
    {
        $lst = $this->expectList($node, 'redirects');
        if ($lst === null) {
            return [];
        }
        if (count($lst) > self::MAX_REDIRECTS) {
            $this->err('redirects', 'at most '.self::MAX_REDIRECTS.' path redirects');

            return [];
        }
        $out = [];
        $seen = [];
        foreach ($lst as $i => $item) {
            $p = "redirects[{$i}]";
            $m = $this->expectMap($item, $p);
            if ($m === null) {
                continue;
            }
            $this->unknownKeys($m, ['from', 'to', 'code', 'keep_path'], $p);
            $src = $this->asStr($m['from'] ?? null, $p.'.from');
            $dst = $this->asStr($m['to'] ?? null, $p.'.to');
            if ($src === null || $dst === null) {
                $this->err($p, "'from' and 'to' are required");

                continue;
            }
            if (! str_starts_with($src, '/')) {
                $src = '/'.$src;
            }
            if (! preg_match(self::ROUTE_PATH_RE, $src) || str_contains($src, '//') || str_contains($src, '..')) {
                $this->err($p.'.from', 'invalid path '.self::repr($src).' (letters, digits and . _ ~ / + @ : , = - only, written decoded without %-escapes)');

                continue;
            }
            if (str_starts_with($dst, '/')) {
                if (! preg_match(self::ROUTE_TARGET_PATH_RE, $dst) || str_contains($dst, '..')) {
                    $this->err($p.'.to', 'invalid target path '.self::repr($dst));

                    continue;
                }
            } elseif (! preg_match(self::ROUTE_URL_RE, $dst)) {
                $this->err($p.'.to', 'must be a /path or an http(s):// URL');

                continue;
            }
            if (in_array($src, $seen, true)) {
                $this->err($p.'.from', 'duplicate redirect from '.self::repr($src));

                continue;
            }
            $seen[] = $src;
            $code = $this->vCode($m, $p);
            $keep = true;
            if (array_key_exists('keep_path', $m)) {
                $keep = $this->asBool($m['keep_path'], $p.'.keep_path');
            }
            if ($code === null || $keep === null) {
                continue;
            }
            $out[] = ['from' => $src, 'to' => $dst, 'code' => $code, 'keep_path' => $keep];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function vProxies(mixed $node): array
    {
        $lst = $this->expectList($node, 'proxies');
        if ($lst === null) {
            return [];
        }
        if (count($lst) > self::MAX_PROXIES) {
            $this->err('proxies', 'at most '.self::MAX_PROXIES.' proxies');

            return [];
        }
        $out = [];
        $seen = [];
        foreach ($lst as $i => $item) {
            $p = "proxies[{$i}]";
            $m = $this->expectMap($item, $p);
            if ($m === null) {
                continue;
            }
            $this->unknownKeys($m, ['prefix', 'upstream', 'strip_prefix', 'preserve_host', 'timeout', 'buffering'], $p);
            $prefix = $this->asStr($m['prefix'] ?? null, $p.'.prefix');
            $upstream = $this->asStr($m['upstream'] ?? null, $p.'.upstream');
            if ($prefix === null || $upstream === null) {
                $this->err($p, "'prefix' and 'upstream' are required");

                continue;
            }
            if (! str_starts_with($prefix, '/')) {
                $prefix = '/'.$prefix;
            }
            if (! str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            if (! preg_match(self::ROUTE_PATH_RE, $prefix) || str_contains($prefix, '//') || str_contains($prefix, '..')) {
                $this->err($p.'.prefix', 'invalid prefix '.self::repr($prefix));

                continue;
            }
            if (! preg_match(self::ROUTE_UPSTREAM_RE, $upstream)) {
                $this->err($p.'.upstream', 'must be http(s)://host[:port][/path] with no query or fragment');

                continue;
            }
            if (in_array($prefix, $seen, true)) {
                $this->err($p.'.prefix', 'duplicate proxy prefix '.self::repr($prefix));

                continue;
            }
            $seen[] = $prefix;
            $entry = ['prefix' => $prefix, 'upstream' => $upstream, 'strip_prefix' => false,
                'preserve_host' => false, 'timeout' => 60, 'buffering' => true];
            $ok = true;
            foreach (['strip_prefix', 'preserve_host', 'buffering'] as $field) {
                if (array_key_exists($field, $m)) {
                    $b = $this->asBool($m[$field], "{$p}.{$field}");
                    if ($b === null) {
                        $ok = false;
                    } else {
                        $entry[$field] = $b;
                    }
                }
            }
            if (array_key_exists('timeout', $m)) {
                $t = $this->asInt($m['timeout'], $p.'.timeout', 1, 3600);
                if ($t === null) {
                    $ok = false;
                } else {
                    $entry['timeout'] = $t;
                }
            }
            if ($ok) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vDeploy(mixed $node): array
    {
        $m = $this->expectMap($node, 'deploy');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['post', 'post_on_failure', 'keep_releases', 'migrate',
            'optimize', 'storage_link', 'queue_restart', 'horizon_terminate', 'extra_artisan', 'snapshot'], 'deploy');
        $out = ['post_on_failure' => 'warn', 'post' => []];

        if (array_key_exists('post_on_failure', $m)) {
            $s = $this->asStr($m['post_on_failure'], 'deploy.post_on_failure');
            if ($s !== null) {
                if ($s !== 'warn' && $s !== 'abort') {
                    $this->err('deploy.post_on_failure', "must be 'warn' or 'abort'");
                } else {
                    $out['post_on_failure'] = $s;
                }
            }
        }

        if (array_key_exists('keep_releases', $m)) {
            $v = $this->asInt($m['keep_releases'], 'deploy.keep_releases', self::KEEP_RELEASES_MIN, self::KEEP_RELEASES_MAX);
            if ($v !== null) {
                $out['keep_releases'] = $v;
            }
        }
        foreach (['migrate', 'optimize', 'storage_link', 'queue_restart', 'horizon_terminate', 'snapshot'] as $field) {
            if (array_key_exists($field, $m)) {
                $b = $this->asBool($m[$field], 'deploy.'.$field);
                if ($b !== null) {
                    $out[$field] = $b;
                }
            }
        }
        if (array_key_exists('extra_artisan', $m)) {
            $lst = $this->expectList($m['extra_artisan'], 'deploy.extra_artisan');
            if ($lst !== null) {
                if (count($lst) > self::MAX_EXTRA_ARTISAN) {
                    $this->err('deploy.extra_artisan', 'at most '.self::MAX_EXTRA_ARTISAN.' commands');
                } else {
                    $cmds = [];
                    $ok = true;
                    foreach ($lst as $i => $item) {
                        $p = "deploy.extra_artisan[{$i}]";
                        $s = $this->asStr($item, $p);
                        if ($s === null) {
                            $ok = false;

                            continue;
                        }
                        if (! preg_match(self::ARTISAN_CMD_RE, $s) || strtolower($s) === 'tinker') {
                            $this->err($p, 'invalid artisan command '.self::repr($s).' (tinker is never allowed)');
                            $ok = false;

                            continue;
                        }
                        if (in_array($s, $cmds, true)) {
                            $this->err($p, 'duplicate command '.self::repr($s));
                            $ok = false;

                            continue;
                        }
                        $cmds[] = $s;
                    }
                    if ($ok) {
                        $out['extra_artisan'] = $cmds;
                    }
                }
            }
        }

        if (! array_key_exists('post', $m)) {
            return $out;
        }
        $lst = $this->expectList($m['post'], 'deploy.post');
        if ($lst === null) {
            return $out;
        }
        if (count($lst) > self::MAX_POST_STEPS) {
            $this->err('deploy.post', 'at most '.self::MAX_POST_STEPS.' steps');

            return $out;
        }
        foreach ($lst as $i => $item) {
            $step = $this->vDeployPostStep($item, "deploy.post[{$i}]");
            if ($step !== null) {
                $out['post'][] = $step;
            }
        }

        return $out;
    }

    private function validPostArg(mixed $arg, string $path): bool
    {
        if (! is_string($arg)) {
            $this->err($path, 'expected a string');

            return false;
        }
        if (! preg_match(self::POST_ARG_RE, $arg)) {
            $this->err($path, 'contains disallowed characters — use letters, digits, -_.:/@=+ only');

            return false;
        }
        if (str_contains($arg, '..')) {
            $this->err($path, 'path traversal (..) is not allowed');

            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function vDeployPostStep(mixed $item, string $path): ?array
    {
        if (is_string($item)) {
            return $this->vDeployPostString(trim($item), $path);
        }
        if (self::isMap($item)) {
            if (array_key_exists('run', $item)) {
                return $this->vDeployPostStructured($item, $path);
            }
            if (count($item) === 1) {
                $runner = (string) array_key_first($item);
                if (in_array($runner, self::POST_RUNNERS, true)) {
                    $val = $item[$runner];
                    if (is_string($val)) {
                        return $this->vDeployPostString("{$runner} ".trim($val), $path);
                    }
                    if (is_array($val)) {
                        $parts = [$runner];
                        foreach (array_values($val) as $j => $a) {
                            $a = is_scalar($a) || $a === null ? self::pyStr($a) : $a;
                            if (! $this->validPostArg($a, "{$path}[{$j}]")) {
                                return null;
                            }
                            $parts[] = $a;
                        }

                        return $this->vDeployPostString(implode(' ', $parts), $path);
                    }
                }
            }
            $this->err($path, "expected a string, a one-key map (artisan: …), or an object with 'run:'");

            return null;
        }
        $this->err($path, 'expected a string or a mapping');

        return null;
    }

    private static function sortedRunners(): string
    {
        $runners = self::POST_RUNNERS;
        sort($runners, SORT_STRING);

        return implode(', ', $runners);
    }

    /** Python str() of a scalar, for argument lists written as YAML scalars. */
    private static function pyStr(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'True' : 'False';
        }
        if ($v === null) {
            return 'None';
        }

        return (string) $v;
    }

    /** @return array<string, mixed>|null */
    private function vDeployPostString(string $s, string $path): ?array
    {
        if ($s === '') {
            $this->err($path, 'empty step');

            return null;
        }
        $parts = preg_split('/\s+/', trim($s)) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
        if ($parts === []) {
            $this->err($path, 'empty step');

            return null;
        }
        $runner = $parts[0];
        if (! in_array($runner, self::POST_RUNNERS, true)) {
            $this->err($path, 'unknown runner '.self::repr($runner).' — allowed: '.self::sortedRunners());

            return null;
        }

        return $this->vDeployPostArgv($runner, array_slice($parts, 1), $path);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function vDeployPostStructured(array $item, string $path): ?array
    {
        $runner = $this->asStr($item['run'] ?? null, $path.'.run');
        if ($runner === null) {
            return null;
        }
        if (! in_array($runner, self::POST_RUNNERS, true)) {
            $this->err($path.'.run', 'unknown runner '.self::repr($runner).' — allowed: '.self::sortedRunners());

            return null;
        }
        $argv = [];
        if ($runner === 'artisan') {
            $cmd = $this->asStr($item['command'] ?? null, $path.'.command');
            if ($cmd === null) {
                $this->err($path, "'command' is required when run is artisan");

                return null;
            }
            if (! preg_match(self::ARTISAN_CMD_RE, $cmd)) {
                $this->err($path.'.command', 'invalid artisan command name');

                return null;
            }
            if (strtolower($cmd) === 'tinker') {
                $this->err($path.'.command', 'artisan tinker is not allowed');

                return null;
            }
            $argv[] = $cmd;
        }
        if (array_key_exists('args', $item)) {
            $alst = $this->expectList($item['args'], $path.'.args');
            if ($alst === null) {
                return null;
            }
            if (count($alst) > self::MAX_POST_ARGS) {
                $this->err($path.'.args', 'at most '.self::MAX_POST_ARGS.' arguments');

                return null;
            }
            foreach ($alst as $j => $a) {
                $a = is_scalar($a) || $a === null ? self::pyStr($a) : $a;
                if (! $this->validPostArg($a, "{$path}.args[{$j}]")) {
                    return null;
                }
                $argv[] = $a;
            }
        } elseif ($runner !== 'artisan') {
            $this->err($path, "'args' is required when run is not artisan");

            return null;
        }
        if (($item['force'] ?? null) === true && $runner === 'artisan' && ! in_array('--force', $argv, true)) {
            $argv[] = '--force';
        }
        foreach (array_keys($item) as $k) {
            if (! in_array($k, ['run', 'command', 'args', 'force'], true)) {
                $this->err("{$path}.{$k}", 'unknown key');
            }
        }

        return $this->vDeployPostArgv($runner, $argv, $path);
    }

    /**
     * @param  list<string>  $argv
     * @return array<string, mixed>|null
     */
    private function vDeployPostArgv(string $runner, array $argv, string $path): ?array
    {
        if (count($argv) > self::MAX_POST_ARGS) {
            $this->err($path, 'at most '.self::MAX_POST_ARGS.' arguments');

            return null;
        }
        if ($runner === 'artisan') {
            if ($argv === []) {
                $this->err($path, "artisan requires a command (e.g. 'artisan cache:clear')");

                return null;
            }
            if (! preg_match(self::ARTISAN_CMD_RE, $argv[0])) {
                $this->err($path, 'invalid artisan command '.self::repr($argv[0]));

                return null;
            }
            if (strtolower($argv[0]) === 'tinker') {
                $this->err($path, 'artisan tinker is not allowed');

                return null;
            }
            foreach (array_slice($argv, 1, null, true) as $j => $a) {
                if (! $this->validPostArg($a, "{$path}[{$j}]")) {
                    return null;
                }
            }
        } elseif (in_array($runner, ['npm', 'npx', 'yarn', 'pnpm'], true)) {
            if ($argv === []) {
                $this->err($path, "{$runner} requires at least one argument (e.g. 'npm run build')");

                return null;
            }
            if (in_array($argv[0], self::NPM_FORBIDDEN, true)) {
                $this->err($path, "{$runner} {$argv[0]} is not allowed");

                return null;
            }
            foreach ($argv as $j => $a) {
                if (! $this->validPostArg($a, "{$path}[{$j}]")) {
                    return null;
                }
            }
        } elseif ($runner === 'composer') {
            if ($argv === []) {
                $this->err($path, 'composer requires at least one argument');

                return null;
            }
            if (in_array($argv[0], self::COMPOSER_FORBIDDEN, true)) {
                $this->err($path, "composer {$argv[0]} is not allowed");

                return null;
            }
            foreach ($argv as $j => $a) {
                if (! $this->validPostArg($a, "{$path}[{$j}]")) {
                    return null;
                }
            }
        } elseif ($runner === 'php' || $runner === 'node') {
            if (count($argv) !== 1) {
                $this->err($path, "{$runner} requires exactly one script path");

                return null;
            }
            if (! preg_match(self::REL_SCRIPT_RE, $argv[0]) || str_starts_with($argv[0], '/')) {
                $this->err($path, 'script must be a relative path under the release (e.g. scripts/warm.mjs)');

                return null;
            }
        }

        return ['run' => $runner, 'argv' => array_values($argv)];
    }

    /** @return array<string, mixed> */
    private function vHealth(mixed $node): array
    {
        $m = $this->expectMap($node, 'health');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['enabled', 'url', 'expect', 'grace', 'postdeploy', 'rollback_on_unhealthy'], 'health');
        $out = [];

        if (array_key_exists('enabled', $m)) {
            $b = $this->asBool($m['enabled'], 'health.enabled');
            if ($b === null) {
                return [];
            }
            $out['enabled'] = $b;
            if ($b === false) {
                foreach (array_keys($m) as $k) {
                    if ($k !== 'enabled') {
                        $this->err('health.'.$k, "cannot be combined with 'enabled: false'");
                    }
                }

                return $out;
            }
        } else {
            $out['enabled'] = true;
        }

        if (! array_key_exists('url', $m)) {
            $this->err('health', "'url' is required (or use 'enabled: false' to remove the healthcheck)");

            return $out;
        }
        $url = $this->asStr($m['url'], 'health.url');
        if ($url === null) {
            return $out;
        }
        if (! preg_match(self::URL_RE, $url)) {
            $this->err('health.url', 'must be a plain http:// or https:// URL with no credentials, query string or fragment');

            return $out;
        }
        $out['url'] = $url;

        if (array_key_exists('expect', $m)) {
            $v = $this->asInt($m['expect'], 'health.expect', 100, 599);
            if ($v !== null) {
                $out['expect'] = $v;
            }
        } else {
            $out['expect'] = 200;
        }

        if (array_key_exists('grace', $m)) {
            $v = $this->asInt($m['grace'], 'health.grace', 0, 120);
            if ($v !== null) {
                $out['grace'] = $v;
            }
        }

        foreach (['postdeploy', 'rollback_on_unhealthy'] as $field) {
            if (array_key_exists($field, $m)) {
                $b = $this->asBool($m[$field], 'health.'.$field);
                if ($b !== null) {
                    $out[$field] = $b;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vApp(mixed $node): array
    {
        $m = $this->expectMap($node, 'app');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['php', 'aliases', 'ini', 'www', 'basic_auth', 'limits'], 'app');
        $out = [];

        if (array_key_exists('limits', $m)) {
            $lm = $this->expectMap($m['limits'], 'app.limits');
            if ($lm !== null) {
                $limits = [];
                $this->unknownKeys($lm, ['memory_limit', ...array_keys(self::LIMIT_BOUNDS)], 'app.limits');
                if (array_key_exists('memory_limit', $lm)) {
                    $s = $lm['memory_limit'];
                    if (is_int($s)) {
                        $s = (string) $s;
                    }
                    $s = $this->asStr($s, 'app.limits.memory_limit');
                    if ($s !== null) {
                        if (! preg_match(self::MEMORY_LIMIT_RE, $s)) {
                            $this->err('app.limits.memory_limit', 'expected a size such as 256M or 1G');
                        } else {
                            $limits['memory_limit'] = $s;
                        }
                    }
                }
                $bounds = self::LIMIT_BOUNDS;
                ksort($bounds);
                foreach ($bounds as $field => [$lo, $hi]) {
                    if (array_key_exists($field, $lm)) {
                        $v = $this->asInt($lm[$field], 'app.limits.'.$field, $lo, $hi);
                        if ($v !== null) {
                            $limits[$field] = $v;
                        }
                    }
                }
                $out['limits'] = $limits;
            }
        }

        if (array_key_exists('www', $m)) {
            $w = $m['www'];
            if ($w === false || $w === null) {
                $w = 'none';
            }
            $w = $this->asStr($w, 'app.www');
            if ($w !== null) {
                if (! in_array($w, self::WWW_MODES, true)) {
                    $this->err('app.www', 'must be one of '.implode(', ', self::WWW_MODES));
                } else {
                    $out['www'] = $w;
                }
            }
        }

        if (array_key_exists('basic_auth', $m)) {
            $ba = $this->vBasicAuth($m['basic_auth']);
            if ($ba !== null) {
                $out['basic_auth'] = $ba;
            }
        }

        if (array_key_exists('php', $m)) {
            $php = $this->asStr($m['php'], 'app.php');
            if ($php !== null) {
                if (! in_array($php, self::PHP_VERSIONS, true)) {
                    $this->err('app.php', 'must be one of '.implode(', ', self::PHP_VERSIONS));
                } else {
                    $out['php'] = $php;
                }
            }
        }

        if (array_key_exists('aliases', $m)) {
            $lst = $this->expectList($m['aliases'], 'app.aliases');
            $aliases = [];
            if ($lst !== null) {
                if (count($lst) > 100) {
                    $this->err('app.aliases', 'at most 100 aliases');
                }
                foreach ($lst as $i => $a) {
                    $p = "app.aliases[{$i}]";
                    $s = $this->asStr($a, $p);
                    if ($s === null) {
                        continue;
                    }
                    if (! preg_match(self::DOMAIN_RE, $s)) {
                        $this->err($p, 'not a valid hostname: '.self::repr($s));

                        continue;
                    }
                    if (in_array($s, $aliases, true)) {
                        $this->err($p, 'duplicate alias '.self::repr($s));

                        continue;
                    }
                    $aliases[] = $s;
                }
            }
            $out['aliases'] = $aliases;
        }

        if (array_key_exists('ini', $m)) {
            $im = $this->expectMap($m['ini'], 'app.ini');
            $ini = [];
            if ($im !== null) {
                foreach ($im as $k => $v) {
                    $k = (string) $k;
                    $p = "app.ini.{$k}";
                    if (! in_array($k, self::INI_KEYS, true)) {
                        $this->err($p, 'not a settable PHP setting (see: cipi ini keys)');

                        continue;
                    }
                    if (is_bool($v)) {
                        $ini[$k] = $v ? 'On' : 'Off';

                        continue;
                    }
                    $s = $this->asStr($v, $p);
                    if ($s === null) {
                        continue;
                    }
                    if (! preg_match('/^[A-Za-z0-9_.,+\/-]{1,64}$/', $s)) {
                        $this->err($p, 'invalid value '.self::repr($s));

                        continue;
                    }
                    $ini[$k] = $s;
                }
            }
            $out['ini'] = $ini;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function vBasicAuth(mixed $node): ?array
    {
        $p = 'app.basic_auth';
        if (is_bool($node)) {
            if ($node) {
                $this->err($p, 'list the users that may log in (basic_auth.users)');

                return null;
            }

            return ['enabled' => false];
        }
        $m = $this->expectMap($node, $p);
        if ($m === null) {
            return null;
        }
        $this->unknownKeys($m, ['enabled', 'users'], $p);
        $enabled = true;
        if (array_key_exists('enabled', $m)) {
            $enabled = $this->asBool($m['enabled'], $p.'.enabled');
            if ($enabled === null) {
                return null;
            }
        }
        if (! $enabled) {
            foreach (array_keys($m) as $k) {
                if ($k !== 'enabled') {
                    $this->err("{$p}.{$k}", "cannot be combined with 'enabled: false'");
                }
            }

            return ['enabled' => false];
        }
        $lst = $this->expectList($m['users'] ?? null, $p.'.users');
        if ($lst === null) {
            return null;
        }
        if ($lst === []) {
            $this->err($p.'.users', 'at least one user is required');

            return null;
        }
        if (count($lst) > self::MAX_BASICAUTH_USERS) {
            $this->err($p.'.users', 'at most '.self::MAX_BASICAUTH_USERS.' users');

            return null;
        }
        $users = [];
        $seen = [];
        foreach ($lst as $i => $item) {
            $ip = "{$p}.users[{$i}]";
            if (is_string($item)) {
                $item = ['name' => $item];
            }
            $um = $this->expectMap($item, $ip);
            if ($um === null) {
                continue;
            }
            $this->unknownKeys($um, ['name', 'password_hash'], $ip);
            $name = $this->asStr($um['name'] ?? null, $ip.'.name');
            if ($name === null) {
                continue;
            }
            if (! preg_match(self::BASICAUTH_USER_RE, $name)) {
                $this->err($ip.'.name', 'invalid user name '.self::repr($name).' (letters, digits, . _ -)');

                continue;
            }
            if (in_array($name, $seen, true)) {
                $this->err($ip.'.name', 'duplicate user '.self::repr($name));

                continue;
            }
            $seen[] = $name;
            $user = ['name' => $name];
            if (array_key_exists('password_hash', $um)) {
                $h = $this->asStr($um['password_hash'], $ip.'.password_hash');
                if ($h === null) {
                    continue;
                }
                if (preg_match(self::BCRYPT_RE, $h, $bm)) {
                    if ((int) $bm[1] < 10) {
                        $this->err($ip.'.password_hash', 'bcrypt cost must be at least 10');

                        continue;
                    }
                } elseif (! preg_match(self::SHA512CRYPT_RE, $h)) {
                    $this->err($ip.'.password_hash', 'must be a bcrypt ($2y$, cost 10+) or SHA-512 crypt ($6$) hash — never a plain password; apr1/MD5 is refused');

                    continue;
                }
                $user['password_hash'] = $h;
            }
            $users[] = $user;
        }

        return ['enabled' => true, 'users' => $users];
    }

    private function dbAllowed(string $name): bool
    {
        if ($this->app === null) {
            return true;
        }

        return $name === $this->app || str_starts_with($name, $this->app.'_');
    }

    /** @return list<array<string, mixed>> */
    private function vDatabases(mixed $node): array
    {
        $lst = $this->expectList($node, 'databases');
        if ($lst === null) {
            return [];
        }
        $out = [];
        $seen = [];
        if (count($lst) > 50) {
            $this->err('databases', 'at most 50 databases');

            return [];
        }
        foreach ($lst as $i => $item) {
            $p = "databases[{$i}]";
            if (is_string($item)) {
                $item = ['name' => $item];
            }
            $m = $this->expectMap($item, $p);
            if ($m === null) {
                continue;
            }
            $this->unknownKeys($m, ['name', 'engine'], $p);
            $name = $this->asStr($m['name'] ?? null, $p.'.name');
            if ($name === null) {
                $this->err($p, "'name' is required");

                continue;
            }
            if (! preg_match(self::DB_NAME_RE, $name)) {
                $this->err($p.'.name', 'invalid database name '.self::repr($name).' (lowercase letters, digits and underscores)');

                continue;
            }
            if (! $this->dbAllowed($name)) {
                $this->err($p.'.name', "must be '{$this->app}' or start with '{$this->app}_' — a project file cannot claim databases outside its own namespace");

                continue;
            }
            if (in_array($name, $seen, true)) {
                $this->err($p.'.name', 'duplicate database '.self::repr($name));

                continue;
            }
            $seen[] = $name;
            $engine = $this->asStr($m['engine'] ?? 'mariadb', $p.'.engine');
            if ($engine === null) {
                continue;
            }
            $engine = ['mysql' => 'mariadb', 'postgres' => 'pgsql', 'postgresql' => 'pgsql'][$engine] ?? $engine;
            if (! in_array($engine, self::ENGINES, true)) {
                $this->err($p.'.engine', 'must be one of '.implode(', ', self::ENGINES));

                continue;
            }
            $out[] = ['name' => $name, 'engine' => $engine];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vWorkers(mixed $node): array
    {
        $m = $this->expectMap($node, 'workers');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['horizon', 'reverb', 'queues'], 'workers');
        $out = [];
        if (array_key_exists('horizon', $m)) {
            $b = $this->asBool($m['horizon'], 'workers.horizon');
            if ($b !== null) {
                $out['horizon'] = $b;
            }
        }
        if (array_key_exists('reverb', $m)) {
            $b = $this->asBool($m['reverb'], 'workers.reverb');
            if ($b !== null) {
                $out['reverb'] = $b;
            }
        }
        if (array_key_exists('queues', $m)) {
            $lst = $this->expectList($m['queues'], 'workers.queues');
            $queues = [];
            $seen = [];
            if ($lst !== null) {
                if (count($lst) > 20) {
                    $this->err('workers.queues', 'at most 20 queue workers');
                    $lst = array_slice($lst, 0, 20);
                }
                foreach ($lst as $i => $item) {
                    $p = "workers.queues[{$i}]";
                    if (is_string($item)) {
                        $item = ['queue' => $item];
                    }
                    $q = $this->expectMap($item, $p);
                    if ($q === null) {
                        continue;
                    }
                    $this->unknownKeys($q, ['queue', 'processes', 'tries', 'timeout'], $p);
                    $name = $this->asStr($q['queue'] ?? null, $p.'.queue');
                    if ($name === null) {
                        $this->err($p, "'queue' is required");

                        continue;
                    }
                    if (! preg_match(self::QUEUE_RE, $name)) {
                        $this->err($p.'.queue', 'invalid queue name '.self::repr($name));

                        continue;
                    }
                    if (in_array($name, $seen, true)) {
                        $this->err($p.'.queue', 'duplicate queue '.self::repr($name));

                        continue;
                    }
                    $seen[] = $name;
                    $entry = ['queue' => $name];
                    foreach ([['processes', 1, 20, 1], ['tries', 1, 100, 3], ['timeout', 10, 86400, 3600]] as [$field, $lo, $hi, $default]) {
                        if (array_key_exists($field, $q)) {
                            $val = $this->asInt($q[$field], "{$p}.{$field}", $lo, $hi);
                            if ($val === null) {
                                continue;
                            }
                            $entry[$field] = $val;
                        } else {
                            $entry[$field] = $default;
                        }
                    }
                    $queues[] = $entry;
                }
            }
            $out['queues'] = $queues;
        }
        if (($out['horizon'] ?? false) && ($out['queues'] ?? []) !== []) {
            $this->err('workers', 'horizon and queues are mutually exclusive — Horizon replaces queue:work workers');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function vBackup(mixed $node): array
    {
        $m = $this->expectMap($node, 'backup');
        if ($m === null) {
            return [];
        }
        $this->unknownKeys($m, ['profiles'], 'backup');
        $lst = $this->expectList($m['profiles'] ?? null, 'backup.profiles');
        if ($lst === null) {
            return [];
        }
        $out = [];
        $seen = [];
        if (count($lst) > 10) {
            $this->err('backup.profiles', 'at most 10 profiles per app');

            return [];
        }
        foreach ($lst as $i => $item) {
            $p = "backup.profiles[{$i}]";
            $pm = $this->expectMap($item, $p);
            if ($pm === null) {
                continue;
            }
            $this->unknownKeys($pm, ['name', 'scope', 'databases', 'exclude_databases', 'exclude_tables',
                'every', 'cron', 'destinations', 'encrypt', 'keep', 'keep_days', 'keep_weeks'], $p);

            $name = $this->asStr($pm['name'] ?? null, $p.'.name');
            if ($name === null) {
                $this->err($p, "'name' is required");

                continue;
            }
            if (! preg_match(self::PROFILE_RE, $name)) {
                $this->err($p.'.name', 'invalid profile name '.self::repr($name));

                continue;
            }
            if ($this->app !== null && $name !== $this->app && ! str_starts_with($name, $this->app.'-')) {
                $this->err($p.'.name', "must be '{$this->app}' or start with '{$this->app}-' — a project file cannot modify another app's backup profiles");

                continue;
            }
            if (in_array($name, $seen, true)) {
                $this->err($p.'.name', 'duplicate profile '.self::repr($name));

                continue;
            }
            $seen[] = $name;

            $prof = ['name' => $name];

            $scope = $this->asStr($pm['scope'] ?? 'all', $p.'.scope');
            if ($scope === null) {
                continue;
            }
            if (! in_array($scope, self::SCOPES, true)) {
                $this->err($p.'.scope', 'must be one of '.implode(', ', self::SCOPES));

                continue;
            }
            $prof['scope'] = $scope;

            foreach ([['databases', self::GLOB_RE], ['exclude_databases', self::GLOB_RE], ['exclude_tables', self::TABLE_GLOB_RE]] as [$field, $rx]) {
                if (! array_key_exists($field, $pm)) {
                    continue;
                }
                $items = $this->expectList($pm[$field], "{$p}.{$field}");
                if ($items === null) {
                    continue;
                }
                $vals = [];
                foreach ($items as $j => $g) {
                    $gp = "{$p}.{$field}[{$j}]";
                    $s = $this->asStr($g, $gp);
                    if ($s === null) {
                        continue;
                    }
                    if (! preg_match($rx, $s)) {
                        $this->err($gp, 'invalid pattern '.self::repr($s));

                        continue;
                    }
                    $vals[] = $s;
                }
                $prof[$field] = $vals;
            }

            if (array_key_exists('every', $pm) && array_key_exists('cron', $pm)) {
                $this->err($p, "use either 'every' or 'cron', not both");

                continue;
            }
            if (array_key_exists('every', $pm)) {
                $s = $this->asStr($pm['every'], $p.'.every');
                if ($s === null) {
                    continue;
                }
                if (! preg_match(self::EVERY_RE, $s, $mm)) {
                    $this->err($p.'.every', 'expected a value like 30m, 6h or 1d');

                    continue;
                }
                $num = (int) $mm[1];
                $unit = $mm[2];
                if ($num === 0) {
                    $this->err($p.'.every', 'must be greater than zero');

                    continue;
                }
                if ($unit === 'm' && ($num > 59 || 60 % $num !== 0)) {
                    $this->err($p.'.every', 'minutes must divide 60 evenly (5m, 10m, 15m, 20m, 30m)');

                    continue;
                }
                if ($unit === 'h' && ($num > 23 || 24 % $num !== 0)) {
                    $this->err($p.'.every', 'hours must divide 24 evenly (1h, 2h, 3h, 4h, 6h, 8h, 12h)');

                    continue;
                }
                if ($unit === 'd' && $num > 28) {
                    $this->err($p.'.every', 'at most 28 days');

                    continue;
                }
                $prof['every'] = $s;
            } elseif (array_key_exists('cron', $pm)) {
                $s = $this->asStr($pm['cron'], $p.'.cron');
                if ($s === null) {
                    continue;
                }
                if (! preg_match(self::CRON_RE, $s) || count(preg_split('/\s+/', trim($s)) ?: []) !== 5) {
                    $this->err($p.'.cron', 'expected five cron fields using digits and * / , - only');

                    continue;
                }
                $prof['cron'] = $s;
            }

            if (array_key_exists('destinations', $pm)) {
                $items = $this->expectList($pm['destinations'], $p.'.destinations');
                if ($items === null) {
                    continue;
                }
                $dests = [];
                foreach ($items as $j => $d) {
                    $dp = "{$p}.destinations[{$j}]";
                    $s = $this->asStr($d, $dp);
                    if ($s === null) {
                        continue;
                    }
                    if (! in_array($s, self::DESTINATIONS, true)) {
                        $this->err($dp, 'must be one of '.implode(', ', self::DESTINATIONS));

                        continue;
                    }
                    if (! in_array($s, $dests, true)) {
                        $dests[] = $s;
                    }
                }
                if ($dests === []) {
                    $this->err($p.'.destinations', 'at least one destination is required');

                    continue;
                }
                $prof['destinations'] = $dests;
            }

            if (array_key_exists('encrypt', $pm)) {
                $b = $this->asBool($pm['encrypt'], $p.'.encrypt');
                if ($b !== null) {
                    $prof['encrypt'] = $b;
                }
            }

            $ret = [];
            foreach ([['keep', 0, 1000], ['keep_days', 0, 3650], ['keep_weeks', 0, 520]] as [$field, $lo, $hi]) {
                if (array_key_exists($field, $pm)) {
                    $val = $this->asInt($pm[$field], "{$p}.{$field}", $lo, $hi);
                    if ($val !== null) {
                        $ret[$field] = $val;
                    }
                }
            }
            if ($ret !== [] && max($ret) <= 0) {
                $this->err($p, 'retention is all zeros — the profile would grow without bound');

                continue;
            }
            if ($ret === []) {
                $this->err($p, 'retention is required — set keep, keep_days or keep_weeks');

                continue;
            }
            $prof['retention'] = [
                'keep' => $ret['keep'] ?? 0,
                'days' => $ret['keep_days'] ?? 0,
                'weeks' => $ret['keep_weeks'] ?? 0,
            ];

            if ($scope === 'db' && ($prof['databases'] ?? null) === []) {
                $this->err($p, "scope 'db' with an empty databases list would back up nothing");

                continue;
            }
            $out[] = $prof;
        }

        return ['profiles' => $out];
    }
}
