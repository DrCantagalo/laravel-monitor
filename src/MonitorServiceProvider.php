<?php

namespace Drcantagalo\LaravelMonitor;

use Illuminate\Support\ServiceProvider;
use Drcantagalo\LaravelMonitor\Http\Middleware\MonitorMethod;
use Illuminate\Contracts\Http\Kernel;

class MonitorServiceProvider extends ServiceProvider
{
    public function boot(Kernel $kernel)
    {
        $jsonPath = storage_path('monitor/installation.json');

        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);
            
            config([
                'monitor.installation_hash' => $data['installation_hash'] ?? null,
                'monitor.local_token'       => $data['local_token'] ?? null,
                'monitor.external_token'    => $data['external_token'] ?? null,
                'monitor.installation_code' => $data['installation_code'] ?? null,
                'monitor.installed_at'      => $data['installed_at'] ?? null,
            ]);
        }

        $kernel->prependMiddleware(\Drcantagalo\LaravelMonitor\Http\Middleware\MonitorMethod::class);

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([\Drcantagalo\LaravelMonitor\Console\Commands\MonitorInstallCommand::class]);
        }

        $this->publishes([
            __DIR__.'/database/migrations' => database_path('migrations'),
        ], 'monitor-migrations');

        $this->publishes([
            __DIR__.'/config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/monitor.php', 'monitor');
    }
}