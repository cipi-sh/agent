<?php

use Illuminate\Support\Facades\Route;
use Cipi\Agent\Http\Controllers\DatabaseController;
use Cipi\Agent\Http\Controllers\HealthController;
use Cipi\Agent\Http\Controllers\McpController;
use Cipi\Agent\Http\Controllers\WebhookController;
use Cipi\Agent\Http\Middleware\VerifyAnonymizerToken;
use Cipi\Agent\Http\Middleware\VerifyMcpToken;
use Cipi\Agent\Http\Middleware\VerifyWebhookToken;

$prefix = config('cipi.route_prefix', 'cipi');

Route::prefix($prefix)->group(function () {

    // Webhook endpoint — receives push events from Git providers
    Route::post('/webhook', [WebhookController::class, 'handle'])
        ->middleware(VerifyWebhookToken::class)
        ->name('cipi.webhook');

    // Health check — optional, token-protected
    if (config('cipi.health_check', true)) {
        Route::get('/health', [HealthController::class, 'check'])
            ->middleware(VerifyWebhookToken::class)
            ->name('cipi.health');
    }

    // MCP server — Model Context Protocol endpoint for AI assistants
    if (config('cipi.mcp_enabled', true)) {
        Route::post('/mcp', [McpController::class, 'handle'])
            ->middleware(VerifyMcpToken::class)
            ->name('cipi.mcp');
    }

    // Database anonymizer — anonymize database dumps for local development
    if (config('cipi.anonymizer_enabled')) {
        Route::post('/db', [DatabaseController::class, 'startAnonymization'])
            ->middleware(VerifyAnonymizerToken::class)
            ->name('cipi.db.anonymize');

        Route::post('/db/user', [DatabaseController::class, 'findUserByEmail'])
            ->middleware(VerifyAnonymizerToken::class)
            ->name('cipi.db.user');

        Route::get('/db/{token}', [DatabaseController::class, 'downloadAnonymized'])
            ->name('cipi.db.download');
    }
});
