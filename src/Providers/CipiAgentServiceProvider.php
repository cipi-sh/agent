<?php

namespace Cipi\Agent\Providers;

use Illuminate\Support\ServiceProvider;
use Cipi\Agent\Console\Commands\CipiDeployKeyCommand;
use Cipi\Agent\Console\Commands\CipiMcpCommand;
use Cipi\Agent\Console\Commands\CipiStatusCommand;

class CipiAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/cipi.php', 'cipi');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/cipi.php' => config_path('cipi.php'),
        ], 'cipi-config');

        // Register routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/cipi.php');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CipiStatusCommand::class,
                CipiDeployKeyCommand::class,
                CipiMcpCommand::class,
            ]);
        }
    }
}
