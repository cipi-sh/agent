<?php

namespace Cipi\Agent\Providers;

use Illuminate\Support\ServiceProvider;
use Cipi\Agent\Console\Commands\CipiAnonymizeCommand;
use Cipi\Agent\Console\Commands\CipiAnonymizerTokenCommand;
use Cipi\Agent\Console\Commands\CipiDeployKeyCommand;
use Cipi\Agent\Console\Commands\CipiHealthTokenCommand;
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
        // Load views
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'cipi');

        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/cipi.php' => config_path('cipi.php'),
            __DIR__ . '/../../config/anonymization.example.json' => base_path('anonymization.example.json'),
        ], 'cipi-config');

        // Publish views
        $this->publishes([
            __DIR__ . '/../../resources/views' => resource_path('views/vendor/cipi'),
        ], 'cipi-views');

        // Register routes
        $this->loadRoutesFrom(__DIR__ . '/../../routes/cipi.php');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CipiStatusCommand::class,
                CipiDeployKeyCommand::class,
                CipiHealthTokenCommand::class,
                CipiMcpCommand::class,
                CipiAnonymizerTokenCommand::class,
                CipiAnonymizeCommand::class,
            ]);
        }
    }
}
