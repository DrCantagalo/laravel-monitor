<?php

namespace Monitor;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use Monitor\Http\Middleware\MonitorMethod;

class MonitorServiceProvider extends ServiceProvider
{
    public function boot(Router $router)
    {
        // Aplica o middleware globalmente a todas as rotas
        $router->pushMiddlewareToGroup('web', MonitorMethod::class);

        // Carrega migrations
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Se tiver rotas próprias
        // $this->loadRoutesFrom(__DIR__.'/routes/api.php');

        // Opcional: publicar config/migrations/views
        /*
        $this->publishes([
            __DIR__.'/config/monitor.php' => config_path('monitor.php'),
        ], 'config');
        */
    }

    public function register()
    {
        // Merge config se houver
        // $this->mergeConfigFrom(
        //     __DIR__.'/config/monitor.php', 'monitor'
        // );
    }
}