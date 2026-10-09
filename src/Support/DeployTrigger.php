<?php

namespace Cipi\Agent\Support;

/**
 * Queues a deploy by dropping ~/.deploy-trigger.
 *
 * The app's own crontab (installed by Cipi) checks for this file every
 * minute, moves it to ~/.deploy-trigger.run and runs `cipi-app-deploy`,
 * which wraps Deployer. That script reads the file as JSON and exports
 * `source`, `actor`, `ip`, `ref` and `request_id` for the root-owned deploy
 * audit ledger, where they are recorded under "claimed" (they come from the
 * app, so root never treats them as facts). The legacy keys this package
 * wrote before 2.0 (`trigger`, `branch`, `timestamp`) are kept for anyone
 * reading the file by hand.
 */
final class DeployTrigger
{
    /**
     * @param  array{actor?: string|null, ip?: string|null, ref?: string|null, request_id?: string|null, branch?: string|null}  $claims
     * @return array<string, mixed>
     */
    public static function queue(string $source, array $claims = []): array
    {
        $appUser = AppHome::user();
        if ($appUser === null) {
            return ['success' => false, 'error' => 'CIPI_APP_USER is not configured'];
        }

        $trigger = AppHome::deployTrigger();
        $home = AppHome::base();

        if ($trigger === null || $home === null || ! is_dir($home)) {
            return ['success' => false, 'error' => "App home not found: {$home}"];
        }

        $source = self::clean($source, 20, '/[^a-z-]/');
        $source = $source !== '' ? $source : 'agent';

        $ref = $claims['ref'] ?? $claims['branch'] ?? null;
        if ($ref === null || $ref === '') {
            $ref = config('cipi.deploy_branch') ?: null;
        }

        $meta = [
            'source' => $source,
            'actor' => self::clean($claims['actor'] ?? ''),
            'ip' => self::clean($claims['ip'] ?? '', 64),
            'ref' => self::clean((string) $ref),
            'request_id' => self::clean($claims['request_id'] ?? '', 64),
            // Legacy fields (cipi/agent < 2.0)
            'trigger' => $source,
            'branch' => $claims['branch'] ?? config('cipi.deploy_branch'),
            'timestamp' => now()->toIso8601String(),
        ];

        $tmp = $trigger.'.tmp-'.bin2hex(random_bytes(4));
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES)."\n";

        if (@file_put_contents($tmp, $json) === false || ! @rename($tmp, $trigger)) {
            @unlink($tmp);

            return ['success' => false, 'error' => "Cannot write deploy trigger to {$trigger}"];
        }

        return [
            'success' => true,
            'message' => 'Deploy queued. The Cipi cron picks it up within 1 minute and runs Deployer.',
            'app' => $appUser,
            'source' => $source,
            'ref' => $meta['ref'] !== '' ? $meta['ref'] : null,
            'trigger_file' => $trigger,
            'queued_at' => $meta['timestamp'],
            'in_progress' => self::inProgress(),
        ];
    }

    /** A trigger has been written and not yet picked up by the cron. */
    public static function pending(): bool
    {
        $path = AppHome::deployTrigger();

        return $path !== null && is_file($path);
    }

    /** The cron picked a trigger up, or Deployer holds its lock. */
    public static function inProgress(): bool
    {
        $running = AppHome::deployTriggerRunning();
        $lock = AppHome::deployLock();

        return ($running !== null && is_file($running)) || ($lock !== null && file_exists($lock));
    }

    /**
     * Printable, bounded and log-safe — the same charset cipi-app-deploy keeps.
     */
    public static function clean(?string $value, int $max = 128, string $strip = '/[^A-Za-z0-9._@:\/+=, -]/'): string
    {
        $value = (string) preg_replace($strip, '', (string) $value);

        return substr($value, 0, $max);
    }
}
