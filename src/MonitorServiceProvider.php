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

        // installation.json (escrito por MonitorInstallCommand) guarda 5
        // campos, mas só local_token é lido de volta em algum lugar do
        // pacote (MonitorController::handle(), pra autenticar chamadas do
        // dashboard) — os outros 4 (installation_hash/external_token/
        // installation_code/installed_at) continuam só no arquivo, sem
        // custo de reprocessar em todo boot sem consumidor.
        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);

            config([
                'monitor.local_token' => $data['local_token'] ?? null,
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