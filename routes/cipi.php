<?php

use Illuminate\Support\Facades\Route;
use Cipi\Agent\Http\Controllers\WebhookController;
use Cipi\Agent\Http\Controllers\HealthController;
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
});
