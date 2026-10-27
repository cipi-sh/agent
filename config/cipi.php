<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Token
    |--------------------------------------------------------------------------
    |
    | Set by Cipi during app creation. Authenticates push webhooks from
    | GitHub, GitLab, Bitbucket, Gitea and Azure DevOps.
    |
    */
    'webhook_token' => env('CIPI_WEBHOOK_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | App User
    |--------------------------------------------------------------------------
    |
    | The Linux user Cipi created for this app. Set automatically by Cipi.
    | Locates the app home (/home/<user>), the deploy trigger and the logs.
    |
    */
    'app_user' => env('CIPI_APP_USER', ''),

    /*
    |--------------------------------------------------------------------------
    | App Home
    |--------------------------------------------------------------------------
    |
    | Override the app home directory. Default: /home/<app_user>.
    |
    */
    'home' => env('CIPI_HOME', null),

    /*
    |--------------------------------------------------------------------------
    | PHP Version
    |--------------------------------------------------------------------------
    |
    | The PHP version Cipi assigned to this app. Informational (health check,
    | cipi.yml generation).
    |
    */
    'php_version' => env('CIPI_PHP_VERSION', PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION),

    /*
    |--------------------------------------------------------------------------
    | Environment Label
    |--------------------------------------------------------------------------
    |
    | The label Cipi gives the app (production, staging, …): CIPI_ENV, written
    | by Cipi 5.8 next to CIPI_DEPLOY_BRANCH. Informational: the agent reports
    | it and looks for cipi-<label>.yml before cipi.yml, as the server does.
    | Older servers do not set it: APP_ENV, then "production", stand in.
    |
    */
    'env' => env('CIPI_ENV', null),

    /*
    |--------------------------------------------------------------------------
    | Deploy Script Path / Branch Filter
    |--------------------------------------------------------------------------
    */
    'deploy_script' => env('CIPI_DEPLOY_SCRIPT', null), // defaults to ~/.deployer/deploy.php
    'deploy_branch' => env('CIPI_DEPLOY_BRANCH', null), // null = any push triggers a deploy

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | /cipi → /cipi/webhook, /cipi/health, /cipi/mcp, /cipi/db
    |
    */
    'route_prefix' => env('CIPI_ROUTE_PREFIX', 'cipi'),

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    |
    | GET /cipi/health — app, database, cache, queue (pending + failed jobs),
    | writable storage and deploy state (release, commit, last deploy).
    | Bearer token: CIPI_HEALTH_TOKEN, falling back to CIPI_WEBHOOK_TOKEN.
    |
    */
    'health_check' => env('CIPI_HEALTH_CHECK', true),
    'health_token' => env('CIPI_HEALTH_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Deploy Log Channel
    |--------------------------------------------------------------------------
    */
    'log_channel' => env('CIPI_LOG_CHANNEL', null), // null = default channel

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | POST /cipi/mcp — Model Context Protocol over Streamable HTTP (JSON-RPC
    | 2.0). Speaks MCP 2024-11-05 … 2025-11-25 (initialize handshake) and
    | 2026-07-28 (stateless, server/discover). Protected by CIPI_MCP_TOKEN.
    | Run `php artisan cipi:mcp` for client configuration snippets.
    |
    */
    'mcp_enabled' => env('CIPI_MCP', false),
    'mcp_token' => env('CIPI_MCP_TOKEN', ''),

    // Browser origins allowed to call the endpoint (DNS-rebinding guard).
    // Comma-separated hosts or origins; "*" allows any. Default: the app's own host.
    'mcp_allowed_origins' => env('CIPI_MCP_ALLOWED_ORIGINS', null),

    // Allow INSERT/UPDATE/DELETE through the db_query tool. false = read-only.
    'mcp_db_write' => env('CIPI_MCP_DB_WRITE', true),

    // Default row cap for db_query results (max 500).
    'mcp_max_rows' => env('CIPI_MCP_MAX_ROWS', 100),

    // Redact common secrets (tokens, passwords, DSNs, APP_KEY) in logs and command output.
    'mcp_redact' => env('CIPI_MCP_REDACT', true),

    // Artisan commands blocked in addition to the built-in list (interactive,
    // long-running and data-destroying commands: serve, tinker, queue:work,
    // horizon, octane:start, db:wipe, migrate:fresh, migrate:reset, …).
    'mcp_blocked_artisan' => [
        // 'migrate:rollback',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Anonymizer
    |--------------------------------------------------------------------------
    |
    | POST /cipi/db queues an anonymized dump (MySQL/MariaDB or PostgreSQL)
    | and e-mails a signed download link. The config lives outside the repo:
    | /home/<app>/.db/anonymization.json (php artisan cipi:init-anonymize --scan).
    |
    */
    'anonymizer_enabled' => env('CIPI_ANONYMIZER', false),
    'anonymizer_token' => env('CIPI_ANONYMIZER_TOKEN', ''),

    // Explicit path of anonymization.json (default: ~/.db/anonymization.json, then ~/.cipi/anonymization.json).
    'anonymizer_config' => env('CIPI_ANONYMIZER_CONFIG', null),

    // Minutes a download link stays valid.
    'anonymizer_ttl' => env('CIPI_ANONYMIZER_TTL', 15),

    // Table queried by POST /cipi/db/user.
    'anonymizer_users_table' => env('CIPI_ANONYMIZER_USERS_TABLE', 'users'),

    // Dump binaries, when they are not on PATH.
    'anonymizer_binaries' => [
        'mysqldump' => env('CIPI_MYSQLDUMP', null),
        'pg_dump' => env('CIPI_PG_DUMP', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cipi Panel API (optional, via cipi/sdk)
    |--------------------------------------------------------------------------
    |
    | With `composer require cipi/sdk` and a Sanctum token from `cipi api
    | token create`, the MCP server also exposes panel_* tools (rollback,
    | deploy audit ledger, database backup, job status, server status).
    | The SDK reads CIPI_BASE_URL and CIPI_TOKEN from this same config file.
    |
    */
    'panel' => env('CIPI_PANEL', true),
    'panel_app' => env('CIPI_PANEL_APP', null), // app name on the panel; default: CIPI_APP_USER

];
