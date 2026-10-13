<?php

namespace Drcantagalo\LaravelMonitor;

use Drcantagalo\LaravelMonitor\Console\Commands\MonitorAccessLogCommand;
use Drcantagalo\LaravelMonitor\Console\Commands\MonitorAuditPathsCommand;
use Drcantagalo\LaravelMonitor\Console\Commands\MonitorDashboardCommand;
use Drcantagalo\LaravelMonitor\Console\Commands\MonitorExportDenylistCommand;
use Drcantagalo\LaravelMonitor\Console\Commands\MonitorInstallCommand;
use Drcantagalo\LaravelMonitor\Console\Commands\MonitorPruneCommand;
use Drcantagalo\LaravelMonitor\Console\Commands\MonitorUpdateCommand;
use Drcantagalo\LaravelMonitor\Http\Middleware\AvoidMonitor;
use Drcantagalo\LaravelMonitor\Http\Middleware\MonitorMethod;
use Drcantagalo\LaravelMonitor\Support\Monitor;
use Drcantagalo\LaravelMonitor\Support\PendingCustomTags;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class MonitorServiceProvider extends ServiceProvider
{
    public function boot(Kernel $kernel, Router $router)
    {
        $jsonPath = storage_path('monitor/installation.json');

        // installation.json (escrito por MonitorInstallCommand) guarda 6
        // campos, mas só local_token (MonitorController::handle(), pra
        // autenticar chamadas do dashboard) e, desde a task 345,
        // main_domain (VisitorOriginDetector::ownSiteBase() — domínio
        // principal do site + subdomínios dele = "próprio site", nunca
        // origem de referer) são lidos de volta em algum lugar do pacote
        // — os outros 4 (installation_hash/external_token/
        // installation_code/installed_at) continuam só no arquivo, sem
        // custo de reprocessar em todo boot sem consumidor. Ler aqui (uma
        // vez por boot = uma vez por request nos setups tradicionais)
        // evita reabrir o arquivo a cada chamada de detect().
        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);

            config([
                'monitor.local_token' => $data['local_token'] ?? null,
                'monitor.main_domain' => $data['main_domain'] ?? null,
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
        $kernel->appendMiddlewareToGroup('web', MonitorMethod::class);

        // Alias `avoid-monitor` (laravel-monitor 151) - exclusão permanente
        // de tracking por rota/grupo, ex: `Route::middleware('avoid-monitor')
        // ->group(...)`. Complementar a `Monitor::skipTracking()` (ver
        // README "Advanced usage"). `aliasMiddleware()` só existe no
        // `Router` (não no `Kernel` — removido do Kernel do Laravel a
        // partir da 11, quando o app-skeleton passou a registrar aliases
        // via `bootstrap/app.php`; um pacote não controla esse arquivo do
        // host app, então registra direto no Router mesmo).
        $router->aliasMiddleware('avoid-monitor', AvoidMonitor::class);

        // O cookie de remember-me é lido diretamente via $request->cookie()
        // no endpoint público, sem passar pelo decrypt padrao do Laravel
        // (o valor gravado é o id-token cru, comparado direto contra
        // Monitor::data->id-token) - precisa continuar excetuado mesmo
        // com o MonitorMethod agora dentro do grupo `web`.
        EncryptCookies::except(config('monitor.remember_cookie', 'monitor_id_token'));

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // A rota pública (`/monitor/handler`) só existe pra alimentar o
        // dashboard SaaS hospedado (monitor.cantagalo.it) - cliente que
        // desligou isso no `monitor:install` (task 138) não precisa dela
        // exposta. Default `true` (config ausente = instalação anterior a
        // essa opção, nunca rodou `monitor:install` de novo) preserva o
        // comportamento de sempre.
        if (config('monitor.dashboard.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                MonitorInstallCommand::class,
                MonitorExportDenylistCommand::class,
                MonitorUpdateCommand::class,
                MonitorAuditPathsCommand::class,
                MonitorPruneCommand::class,
                MonitorAccessLogCommand::class,
                MonitorDashboardCommand::class,
            ]);
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

        $this->app->singleton('monitor', fn () => new Monitor);

        // Scoped: lista de tags custom pendentes de `Monitor::addTags()`
        // enquanto `session('monitor_id')` ainda não existe (primeira
        // request de um visitante novo) — ver docblock de
        // `PendingCustomTags`. NUNCA Cache (global entre
        // requests/usuários) nem sessão (ainda não existe pra esse
        // visitante nesse ponto). `scoped()` (em vez de `singleton()`) é
        // só uma garantia extra pra quando o pacote roda sob Octane/
        // workers de fila de vida longa, que reciclam bindings `scoped`
        // entre execuções — em PHP-FPM/`artisan serve` tradicional cada
        // request já ganha um container novo por conta própria.
        $this->app->scoped(PendingCustomTags::class);
    }
}
