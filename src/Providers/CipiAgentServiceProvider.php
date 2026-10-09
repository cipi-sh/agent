<?php

namespace Cipi\Agent\Providers;

use Cipi\Agent\Console\Commands\CipiAnonymizeAuditCommand;
use Cipi\Agent\Console\Commands\CipiAnonymizeCommand;
use Cipi\Agent\Console\Commands\CipiDeployKeyCommand;
use Cipi\Agent\Console\Commands\CipiGenerateTokenCommand;
use Cipi\Agent\Console\Commands\CipiInitAnonymizeCommand;
use Cipi\Agent\Console\Commands\CipiMcpCommand;
use Cipi\Agent\Console\Commands\CipiServiceCommand;
use Cipi\Agent\Console\Commands\CipiStatusCommand;
use Cipi\Agent\Console\Commands\CipiYmlCommand;
use Cipi\Agent\Mcp\Registry;
use Cipi\Agent\Mcp\Server;
use Illuminate\Support\ServiceProvider;

class CipiAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/cipi.php', 'cipi');

        $this->app->singleton(Registry::class, fn () => new Registry);
        $this->app->singleton(Server::class, fn ($app) => new Server($app->make(Registry::class)));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'cipi');

        // `cipi-config` is kept for backward compatibility; cipi/sdk publishes
        // its own config/cipi.php under the same tag, so `cipi-agent-config`
        // lets you publish just this one when both packages are installed.
        $this->publishes([__DIR__.'/../../config/cipi.php' => config_path('cipi.php')], ['cipi-config', 'cipi-agent-config']);
        $this->publishes([__DIR__.'/../../resources/views' => resource_path('views/vendor/cipi')], 'cipi-views');
        $this->publishes([
            __DIR__.'/../../resources/schema/cipi.schema.json' => base_path('cipi.schema.json'),
            __DIR__.'/../../resources/schema/anonymization.schema.json' => base_path('anonymization.schema.json'),
        ], 'cipi-schemas');

        $this->loadRoutesFrom(__DIR__.'/../../routes/cipi.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CipiStatusCommand::class,
                CipiDeployKeyCommand::class,
                CipiGenerateTokenCommand::class,
                CipiServiceCommand::class,
                CipiMcpCommand::class,
                CipiYmlCommand::class,
                CipiInitAnonymizeCommand::class,
                CipiAnonymizeCommand::class,
                CipiAnonymizeAuditCommand::class,
            ]);
        }
    }
}
