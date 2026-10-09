<?php

namespace Cipi\Agent\Support;

/**
 * What is deployed right now, read from the Deployer layout Cipi uses.
 *
 * Deployer writes a REVISION file (the commit hash) into every release and
 * points ~/current at the live one; cipi-app-deploy writes a readable banner
 * for every automatic deploy into ~/logs/deploy.log. Older heuristics
 * (~/.cipi/deploy.json, .git/HEAD, `git rev-parse`) stay as fallbacks for
 * apps that are not deployed by Cipi.
 */
final class DeployInfo
{
    public static function currentRelease(): ?string
    {
        $link = AppHome::currentLink();
        if ($link === null || ! is_link($link)) {
            return null;
        }

        $target = @readlink($link);

        return $target === false || $target === '' ? null : basename($target);
    }

    public static function currentReleasePath(): ?string
    {
        $link = AppHome::currentLink();
        if ($link === null || ! file_exists($link)) {
            return null;
        }

        $real = @realpath($link);

        return $real === false ? null : $real;
    }

    /**
     * @return list<array{name: string, commit: string|null, current: bool, created_at: string|null}>
     */
    public static function releases(int $limit = 20): array
    {
        $dir = AppHome::releasesDir();
        if ($dir === null || ! is_dir($dir)) {
            return [];
        }

        $current = self::currentRelease();
        $entries = @scandir($dir, SCANDIR_SORT_DESCENDING) ?: [];
        $out = [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || ! is_dir("{$dir}/{$name}")) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'commit' => self::revisionOf("{$dir}/{$name}"),
                'current' => $name === $current,
                'created_at' => ($mtime = @filemtime("{$dir}/{$name}")) ? date(DATE_ATOM, $mtime) : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public static function revisionOf(string $releasePath): ?string
    {
        $file = "{$releasePath}/REVISION";
        if (! is_file($file)) {
            return null;
        }
        $hash = strtolower(trim((string) @file_get_contents($file, false, null, 0, 64)));

        return preg_match('/^[a-f0-9]{7,40}$/', $hash) ? $hash : null;
    }

    /**
     * The deployed commit, from the most reliable source available.
     *
     * @return array{commit: string|null, source: string|null}
     */
    public static function commit(): array
    {
        if (($path = self::currentReleasePath()) !== null && ($rev = self::revisionOf($path)) !== null) {
            return ['commit' => $rev, 'source' => 'REVISION'];
        }

        $base = AppHome::base();
        if ($base !== null) {
            $json = "{$base}/.cipi/deploy.json";
            if (is_file($json)) {
                $data = json_decode((string) @file_get_contents($json), true);
                if (is_array($data) && self::looksLikeHash($data['commit'] ?? null)) {
                    return ['commit' => strtolower($data['commit']), 'source' => '.cipi/deploy.json'];
                }
            }
            $last = "{$base}/.cipi/last_commit";
            if (is_file($last)) {
                $hash = trim((string) @file_get_contents($last));
                if (self::looksLikeHash($hash)) {
                    return ['commit' => strtolower($hash), 'source' => '.cipi/last_commit'];
                }
            }
        }

        $head = base_path('.git/HEAD');
        if (is_file($head)) {
            $content = trim((string) @file_get_contents($head));
            if (preg_match('/^ref: (.+)$/', $content, $m)) {
                $ref = base_path('.git/'.trim($m[1]));
                if (is_file($ref)) {
                    $hash = trim((string) @file_get_contents($ref));
                    if (self::looksLikeHash($hash)) {
                        return ['commit' => strtolower($hash), 'source' => '.git'];
                    }
                }
            } elseif (self::looksLikeHash($content)) {
                return ['commit' => strtolower($content), 'source' => '.git'];
            }
        }

        if (function_exists('shell_exec')) {
            $out = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null');
            $hash = trim((string) $out);
            if (self::looksLikeHash($hash)) {
                return ['commit' => strtolower($hash), 'source' => 'git'];
            }
        }

        return ['commit' => null, 'source' => null];
    }

    /**
     * Summary of the last automatic deploy, from the banners cipi-app-deploy
     * writes to ~/logs/deploy.log.
     *
     * @return array<string, mixed>|null
     */
    public static function lastDeploy(): ?array
    {
        $log = AppHome::deployLog();
        if ($log === null || ! is_file($log)) {
            return null;
        }

        $tail = self::tail($log, 400);
        $start = null;
        $end = null;

        foreach (array_reverse($tail) as $line) {
            if ($end === null && preg_match('/^\[([^\]]+)\] ===== deploy (OK|FAILED)\s+app=(\S+)\s+release=(\S+)\s+duration=(\d+)s\s+exit=(\d+)/', $line, $m)) {
                $end = ['at' => $m[1], 'status' => $m[2] === 'OK' ? 'ok' : 'failed', 'release' => $m[4], 'duration_seconds' => (int) $m[5], 'exit_code' => (int) $m[6]];
                continue;
            }
            if (preg_match('/^\[([^\]]+)\] ===== deploy start\s+app=(\S+)\s+trigger=(\S+)\s+branch=(\S+)\s+from-release=(\S+)/', $line, $m)) {
                $start = ['at' => $m[1], 'trigger' => $m[3], 'branch' => $m[4], 'from_release' => $m[5]];
                break;
            }
        }

        if ($start === null && $end === null) {
            return null;
        }

        $running = $end === null || ($start !== null && $start['at'] > $end['at']);

        return [
            'status' => $running ? 'running' : $end['status'],
            'started_at' => $start['at'] ?? null,
            'finished_at' => $running ? null : ($end['at'] ?? null),
            'trigger' => $start['trigger'] ?? null,
            'branch' => $start['branch'] ?? null,
            'from_release' => $start['from_release'] ?? null,
            'release' => $running ? null : ($end['release'] ?? null),
            'duration_seconds' => $running ? null : ($end['duration_seconds'] ?? null),
            'exit_code' => $running ? null : ($end['exit_code'] ?? null),
            'log' => $log,
        ];
    }

    /**
     * @return list<string>
     */
    public static function tail(string $path, int $lines): array
    {
        if (! is_file($path) || filesize($path) === 0) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $total = $file->key();
        $file->seek(max(0, $total - $lines));

        $buffer = [];
        while (! $file->eof()) {
            $line = $file->fgets();
            if ($line !== false && $line !== '') {
                $buffer[] = $line;
            }
        }

        return $buffer;
    }

    private static function looksLikeHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-fA-F0-9]{7,40}$/', $value) === 1;
    }
}
