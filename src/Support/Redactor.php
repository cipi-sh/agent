<?php

namespace Cipi\Agent\Support;

/**
 * Redacts common secrets before text leaves the server through MCP.
 *
 * Mirrors the production-content guard of the Cipi panel API (cipi/api):
 * logs and command output are prefixed with a warning and the obvious
 * credentials (env assignments, bearer tokens, DSNs, passwords) are masked.
 * The model still gets what it needs to debug; the operator does not leak
 * APP_KEY in a chat transcript by accident.
 */
final class Redactor
{
    public const LOG_WARNING = 'Production content: these are live logs and may include personal data or secrets.';

    public const HIGH_RISK_ALERT = 'WARNING: high-risk patterns detected (token, payment card data or connection string). Review before using.';

    public static function enabled(): bool
    {
        return (bool) config('cipi.mcp_redact', true);
    }

    public static function logs(string $content): string
    {
        return self::wrap($content, true);
    }

    public static function sensitive(string $content): string
    {
        return self::wrap($content, false);
    }

    private static function wrap(string $content, bool $logWarning): string
    {
        if (! self::enabled()) {
            return $content;
        }

        $redacted = self::redact($content);
        $parts = [];

        if ($logWarning) {
            $parts[] = self::LOG_WARNING;
        }
        if (self::hasHighRiskPatterns($redacted)) {
            $parts[] = self::HIGH_RISK_ALERT;
        }
        $parts[] = $redacted;

        return implode("\n\n", $parts);
    }

    public static function redact(string $content): string
    {
        $patterns = [
            // KEY=value assignments of well-known secret variables (.env, phpinfo, dumps)
            '/\b([A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|APP_KEY|PRIVATE_KEY|ACCESS_KEY|CLIENT_SECRET|DSN)[A-Z0-9_]*)\s*=\s*(?!\[REDACTED\])\S+/i' => '$1=[REDACTED]',
            // "password: xxx", "Token: xxx"
            '/\b(password|passwd|pwd|token|secret|api[_ -]?key|webhook token|new password)\s*[:=]\s*(?!\[REDACTED\])\S+/i' => '$1: [REDACTED]',
            // Authorization headers / bearer tokens
            '/\bBearer\s+(?!\[REDACTED\])\S+/i' => 'Bearer [REDACTED]',
            '/\bAuthorization\s*:\s*(?!\[REDACTED\])\S+/i' => 'Authorization: [REDACTED]',
            // Connection strings with credentials
            '/\b((?:mysql|mariadb|pgsql|postgres(?:ql)?|redis|rediss|amqp|mongodb(?:\+srv)?|smtp|ftp|sftp|https?):\/\/)([^\s\/:@]+):([^\s@]+)@/i' => '$1$2:[REDACTED]@',
            // Cipi tokens by prefix
            '/\bcipi_(?:mcp|health|anonymizer|webhook)_[a-f0-9]{16,}\b/i' => '[REDACTED]',
            // Laravel APP_KEY values
            '/\bbase64:[A-Za-z0-9+\/]{40,}={0,2}/' => 'base64:[REDACTED]',
            // Common provider tokens
            '/\b(?:sk-[A-Za-z0-9_-]{20,}|sk_(?:live|test)_[A-Za-z0-9]{16,}|ghp_[A-Za-z0-9]{36,}|gho_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{20,}|xox[baprs]-[A-Za-z0-9-]{10,}|AKIA[A-Z0-9]{16})\b/' => '[REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }

        return $content;
    }

    public static function hasHighRiskPatterns(string $content): bool
    {
        $patterns = [
            '/(?:mysql|mariadb|pgsql|postgres(?:ql)?|mongodb|redis|amqp):\/\/[^\s\'"]+/i',
            '/\b(?:\d{4}[-\s]?){3}\d{4}\b/',
            '/\b(?:sk-[A-Za-z0-9]{20,}|ghp_[A-Za-z0-9]{36,}|gho_[A-Za-z0-9]{36,}|xox[baprs]-[A-Za-z0-9-]{10,}|AKIA[A-Z0-9]{16})\b/',
            '/-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }
}
