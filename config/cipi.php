<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Token
    |--------------------------------------------------------------------------
    |
    | This token is automatically set by Cipi during app creation.
    | It's used to authenticate incoming webhook requests from
    | GitHub, GitLab, Bitbucket, Gitea, or any Git provider.
    |
    */
    'webhook_token' => env('CIPI_WEBHOOK_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | App User
    |--------------------------------------------------------------------------
    |
    | The Linux username for this application. Set automatically by Cipi.
    | Used to locate deploy scripts and home directory.
    |
    */
    'app_user' => env('CIPI_APP_USER', ''),

    /*
    |--------------------------------------------------------------------------
    | PHP Version
    |--------------------------------------------------------------------------
    |
    | The PHP version assigned to this app by Cipi.
    | Informational — used in health check responses.
    |
    */
    'php_version' => env('CIPI_PHP_VERSION', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION),

    /*
    |--------------------------------------------------------------------------
    | Deploy Script Path
    |--------------------------------------------------------------------------
    |
    | Path to the Deployer config file. Cipi generates this automatically.
    | Override only if you have a custom deployer setup.
    |
    */
    'deploy_script' => env('CIPI_DEPLOY_SCRIPT', null), // defaults to ~/.deployer/deploy.php

    /*
    |--------------------------------------------------------------------------
    | Deploy Branch Filter
    |--------------------------------------------------------------------------
    |
    | Only trigger deploy when a push is made to this branch.
    | Set to null to deploy on any push.
    |
    */
    'deploy_branch' => env('CIPI_DEPLOY_BRANCH', null), // null = use Deployer's configured branch

    /*
    |--------------------------------------------------------------------------
    | Webhook Route Prefix
    |--------------------------------------------------------------------------
    |
    | The URL prefix for Cipi routes.
    | Default: /cipi → webhook at /cipi/webhook, health at /cipi/health
    |
    */
    'route_prefix' => env('CIPI_ROUTE_PREFIX', 'cipi'),

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    |
    | Enable/disable the health check endpoint at /cipi/health.
    | The health check reports app status, DB, cache, and queue.
    |
    */
    'health_check' => env('CIPI_HEALTH_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Deploy Log
    |--------------------------------------------------------------------------
    |
    | Log channel for deploy events. Uses Laravel's logging system.
    |
    */
    'log_channel' => env('CIPI_LOG_CHANNEL', null), // null = default channel

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | Enable the MCP (Model Context Protocol) endpoint at /cipi/mcp.
    | This exposes a JSON-RPC 2.0 server that AI assistants (Cursor, Claude
    | Desktop, etc.) can connect to for app monitoring and deploy management.
    |
    | The endpoint is protected by CIPI_MCP_TOKEN Bearer token.
    | Run `php artisan cipi:mcp` to get the client configuration snippet.
    |
    */
    'mcp_enabled' => env('CIPI_MCP_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | MCP Token
    |--------------------------------------------------------------------------
    |
    | Dedicated token for MCP server access. Use this instead of the webhook
    | token for AI assistant integrations. Generate with `php artisan cipi:mcp-token`.
    |
    */
    'mcp_token' => env('CIPI_MCP_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Database Anonymizer
    |--------------------------------------------------------------------------
    |
    | Enable the database anonymization feature. When enabled, provides endpoints
    | to create anonymized database dumps for local development/testing.
    |
    |
    */
    'anonymizer_enabled' => env('CIPI_ANONYMIZER_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Anonymizer Token
    |--------------------------------------------------------------------------
    |
    | Dedicated token for database anonymization API access. Required to trigger
    | database dumps and anonymization jobs.
    |
    */
    'anonymizer_token' => env('CIPI_ANONYMIZER_TOKEN', ''),

];
