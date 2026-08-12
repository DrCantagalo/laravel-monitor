<?php

namespace Drcantagalo\LaravelMonitor;

use Illuminate\Support\ServiceProvider;
use Drcantagalo\LaravelMonitor\Http\Middleware\MonitorMethod;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;

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

        // Registrado dentro do grupo `web`, depois do StartSession (append
        // via appendMiddlewareToGroup entra por último no grupo) - precisa
        // rodar aninhado DENTRO do StartSession para que sua fase de
        // "volta" (leitura/escrita de session('monitor_id') apos o
        // $next($request)) execute antes da fase de "volta" do
        // StartSession, que é quem persiste a sessao no driver e anexa o
        // cookie de sessao na response. Antes era prependMiddleware()
        // (middleware global, fora de qualquer grupo) e por isso rodava
        // por fora do StartSession: a sessao ja tinha sido salva antes do
        // MonitorMethod gravar monitor_id nela, entao a gravacao nunca
        // persistia entre requests (bug: cada request criava um Monitor
        // novo em vez de reaproveitar o da sessao).
        $kernel->appendMiddlewareToGroup('web', \Drcantagalo\LaravelMonitor\Http\Middleware\MonitorMethod::class);

        // O cookie de remember-me é lido diretamente via $request->cookie()
        // no endpoint público, sem passar pelo decrypt padrao do Laravel
        // (o valor gravado é o id-token cru, comparado direto contra
        // Monitor::data->id-token) - precisa continuar excetuado mesmo
        // com o MonitorMethod agora dentro do grupo `web`.
        EncryptCookies::except(config('monitor.remember_cookie', 'monitor_id_token'));

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

        $this->app->singleton('monitor', fn () => new \Drcantagalo\LaravelMonitor\Support\Monitor());
    }
}