<?php

namespace Cipi\Agent\Panel;

/**
 * Optional bridge to the Cipi panel API through the cipi/sdk package.
 *
 * The agent itself only knows the app it runs in. With the SDK installed
 * (`composer require cipi/sdk`) and a Sanctum token in the environment
 * (CIPI_BASE_URL + CIPI_TOKEN, created with `cipi api token create` and
 * scoped to the abilities you want to allow), the MCP server can also roll a
 * release back, read the deploy audit ledger, start a database backup and
 * follow panel jobs — things no in-app process can do on its own.
 */
final class PanelClient
{
    public static function installed(): bool
    {
        return class_exists(\Cipi\Sdk\Cipi::class);
    }

    public static function configured(): bool
    {
        if (! self::installed() || ! (bool) config('cipi.panel', true)) {
            return false;
        }

        return (string) config('cipi.base_url', '') !== '' && (string) config('cipi.token', '') !== '';
    }

    /** The app name on the panel: CIPI_PANEL_APP, or the Linux user Cipi gave the app. */
    public static function appName(): ?string
    {
        $name = (string) config('cipi.panel_app', '') ?: (string) config('cipi.app_user', '');

        return $name !== '' ? $name : null;
    }

    /**
     * @return object a \Cipi\Sdk\Cipi instance
     */
    public static function sdk(): object
    {
        if (! self::installed()) {
            throw new \RuntimeException('cipi/sdk is not installed: composer require cipi/sdk');
        }
        if (app()->bound(\Cipi\Sdk\Cipi::class)) {
            return app(\Cipi\Sdk\Cipi::class);
        }

        return \Cipi\Sdk\Cipi::connect((string) config('cipi.base_url'), (string) config('cipi.token'), [
            'timeout' => (int) config('cipi.timeout', 30),
            'connect_timeout' => (int) config('cipi.connect_timeout', 10),
            'allow_http' => filter_var(config('cipi.allow_http', false), FILTER_VALIDATE_BOOL),
            'max_retries' => (int) config('cipi.max_retries', 2),
        ]);
    }

    /**
     * Human message for an SDK exception without leaking the token.
     */
    public static function describe(\Throwable $e): string
    {
        $status = is_object($e) && property_exists($e, 'status') ? (int) $e->status : 0;
        $hint = match ($status) {
            401 => ' — the panel rejected the token (CIPI_TOKEN).',
            403 => ' — the token lacks the required ability (create one with `cipi api token create`).',
            404 => ' — not found on the panel (check CIPI_PANEL_APP / CIPI_APP_USER).',
            default => '',
        };

        return 'Panel API error: '.$e->getMessage().$hint;
    }
}
