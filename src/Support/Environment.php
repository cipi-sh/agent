<?php

namespace Cipi\Agent\Support;

/**
 * The environment label Cipi gives the app (cipi app create --env, cipi app
 * edit --env): production, staging, qa, … Cipi 5.8 writes it into the app's
 * .env as CIPI_ENV (and APP_ENV for Laravel apps) and reads cipi-<label>.yml
 * before cipi.yml, so one repository can carry a file per environment.
 *
 * The agent only reports it: CIPI_ENV, then APP_ENV (older servers do not set
 * CIPI_ENV), then production. A value that is not a valid label is skipped.
 */
final class Environment
{
    public const DEFAULT = 'production';

    /** Same rule as the server: ^[a-z0-9][a-z0-9_-]{0,31}$ */
    public const LABEL_RE = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    public static function label(): string
    {
        foreach ([config('cipi.env'), app()->environment()] as $candidate) {
            if (is_string($candidate) && self::valid(trim($candidate))) {
                return trim($candidate);
            }
        }

        return self::DEFAULT;
    }

    public static function valid(string $label): bool
    {
        return preg_match(self::LABEL_RE, $label) === 1;
    }

    /**
     * The file names Cipi looks for in a directory, in order: the
     * environment's own file first, then the plain one.
     *
     * @return list<string>
     */
    public static function ymlNames(?string $label = null): array
    {
        $label ??= self::label();

        return ["cipi-{$label}.yml", "cipi-{$label}.yaml", 'cipi.yml', 'cipi.yaml'];
    }
}
