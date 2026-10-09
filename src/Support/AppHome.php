<?php

namespace Cipi\Agent\Support;

/**
 * Paths inside the app's home directory on a Cipi server.
 *
 * Cipi provisions every app as a Linux user with a fixed layout under
 * /home/<user>: `current` (symlink to the live release), `releases/`,
 * `shared/` (.env, storage), `logs/` (nginx, php-fpm, workers, deploy),
 * `.deployer/deploy.php` and the `.deploy-trigger` consumed by the app's
 * crontab. The base directory can be overridden with CIPI_HOME (tests,
 * unusual layouts).
 */
final class AppHome
{
    public static function user(): ?string
    {
        $user = (string) config('cipi.app_user', '');

        return $user !== '' ? $user : null;
    }

    public static function base(): ?string
    {
        $custom = (string) config('cipi.home', '');
        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        $user = self::user();

        return $user === null ? null : "/home/{$user}";
    }

    public static function path(string $relative = ''): ?string
    {
        $base = self::base();
        if ($base === null) {
            return null;
        }

        $relative = ltrim($relative, '/');

        return $relative === '' ? $base : "{$base}/{$relative}";
    }

    public static function logsDir(): ?string
    {
        return self::path('logs');
    }

    public static function sharedDir(): ?string
    {
        return self::path('shared');
    }

    public static function releasesDir(): ?string
    {
        return self::path('releases');
    }

    public static function currentLink(): ?string
    {
        return self::path('current');
    }

    public static function deployTrigger(): ?string
    {
        return self::path('.deploy-trigger');
    }

    public static function deployTriggerRunning(): ?string
    {
        return self::path('.deploy-trigger.run');
    }

    public static function deployLock(): ?string
    {
        return self::path('.dep/deploy.lock');
    }

    public static function deployLog(): ?string
    {
        return self::path('logs/deploy.log');
    }

    /**
     * Where the anonymization config is looked for, in order.
     *
     * @return list<string>
     */
    public static function anonymizationConfigCandidates(): array
    {
        $custom = (string) config('cipi.anonymizer_config', '');
        $paths = [];
        if ($custom !== '') {
            $paths[] = $custom;
        }
        if (($base = self::base()) !== null) {
            $paths[] = "{$base}/.db/anonymization.json";
            $paths[] = "{$base}/.cipi/anonymization.json";
        }

        return $paths;
    }

    public static function anonymizationConfig(): ?string
    {
        foreach (self::anonymizationConfigCandidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
