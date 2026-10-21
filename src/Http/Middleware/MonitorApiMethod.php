<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Drcantagalo\LaravelMonitor\Http\Middleware\Concerns\BlocksInboundRequests;
use Drcantagalo\LaravelMonitor\Models\MonitorApiBlockEvent;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\ApiTokenVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\ApiVisitRecorder;
use Drcantagalo\LaravelMonitor\Support\ScraperSignalDetector;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * laravel-monitor 400: equivalente de `MonitorMethod`, mas pro grupo `api`
 * — registrado pelo próprio pacote (`MonitorServiceProvider::boot()`) do
 * mesmo jeito que `MonitorMethod` já se registra em `web`. Antes desta
 * task, requests do grupo `api` (ex: rotas Sanctum-autenticadas de um app
 * consumidor) eram completamente invisíveis ao pacote — sem tracking, sem
 * enforcement de bloqueio por IP.
 *
 * Bloqueio na IDA (`isBlocked`/`isPathBlocked`, via `BlocksInboundRequests`
 * — mesmo trait de `MonitorMethod`, só precisa do IP): roda ANTES de
 * `$next($request)`, antes de qualquer middleware de auth do app
 * hospedeiro (`auth:sanctum` de uma rota específica roda depois, nunca
 * antes, já que este middleware é anexado ao FINAL do grupo `api`).
 * Tracking na VOLTA (depois de `$next`): só então decide o grouping
 * (token válido vs fallback por IP) — resolver o token é feito por ESTA
 * classe (`resolveSanctumToken()`, via `PersonalAccessToken::findToken()`,
 * NUNCA via `Auth`/guard), não pelo que a rota do host decidiu fazer com
 * `auth:sanctum` — então a classificação funciona mesmo numa rota `api`
 * pública (sem `auth:sanctum` nenhum) que só opcionalmente aceita um
 * Bearer token.
 */
class MonitorApiMethod
{
    use BlocksInboundRequests;

    /**
     * A própria rota pública do pacote (`routes/api.php`, grupo `api` +
     * `MonitorCors`) NUNCA deve ser tratada como tráfego do app
     * hospedeiro: ela já tem sua própria auth (`local_token`/read-token,
     * ver `MonitorController::handle()`), e o CALLER de verdade é
     * normalmente o próprio servidor do dashboard SaaS (monitor.cantagalo.it)
     * batendo com frequência — sem este guard, cada chamada do dashboard
     * viraria um "Monitor" fantasma rastreado por IP (e, pior, um sinal
     * `invalid_api_token` a cada chamada, já que o `local_token` nunca é
     * um token Sanctum de verdade). `MonitorMethod` nunca teve esse
     * problema porque a rota do handler sempre usou o grupo `api`, nunca
     * `web` — esta classe é a PRIMEIRA vez que o pacote se anexa ao grupo
     * `api`, então é a primeira vez que esse auto-tracking seria possível.
     */
    protected const OWN_ROUTE_PATTERN = 'monitor/handler';

    public function __construct(
        protected AnonymousVisitorTracker $anonymousTracker,
        protected ApiTokenVisitorTracker $apiTokenTracker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningInConsole()) {
            return $next($request);
        }

        if ($request->is(self::OWN_ROUTE_PATTERN)) {
            return $next($request);
        }

        $pathOnly = ltrim($request->path(), '/');
        $path = $request->getHost().'/'.$pathOnly;
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        if ($this->isIgnoredIp($ip)) {
            return $next($request);
        }

        // Resolvido ANTES do enforcement de bloqueio: precisamos saber se
        // havia um token válido mesmo quando a request é cortada aqui
        // (item 5 da task — "request bloqueada com token válido" captura
        // user/token/ip). `$invalidTokenAttempt` distingue "mandou um
        // Bearer token e o Sanctum rejeitou" de "não mandou token nenhum"
        // — só o primeiro conta como sinal de scraper (item 6).
        $bearerToken = $request->bearerToken();
        $token = null;
        $invalidTokenAttempt = false;

        if ($bearerToken && $this->sanctumAvailable()) {
            $token = $this->resolveSanctumToken($bearerToken);
            $invalidTokenAttempt = $token === null;
        }

        try {
            $ipBlocked = $this->isBlocked($ip);
            $pathBlocked = ! $ipBlocked && $this->isPathBlocked($pathOnly);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_blocked_ips ou monitor_paths não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
            $ipBlocked = false;
            $pathBlocked = false;
        }

        if ($ipBlocked || $pathBlocked) {
            if ($pathBlocked) {
                $this->registerHoneypotOffense($ip);
            }

            $this->recordBlockedAttempt($ip);

            // Item 5: só no bloqueio de IP de verdade (não no primeiro hit
            // de honeypot, que ainda nem colocou o IP em
            // monitor_blocked_ips por si só) — ver docblock da classe.
            if ($ipBlocked && $token !== null) {
                $this->recordValidTokenBlockEvent($token, $ip);
            }

            // Item 5: JSON explícito (NUNCA a página HTML de bloqueio do
            // `abort()` usado por `MonitorMethod`) — um cliente de API que
            // não manda `Accept: application/json` ainda receberia HTML
            // do handler de exceções padrão do Laravel se usássemos
            // `abort()` aqui.
            return response()->json([
                'success' => false,
                'message' => 'Blocked',
            ], $pathBlocked ? 404 : 403);
        }

        $response = $next($request);

        try {
            $notFound = $response->getStatusCode() === 404;

            if ($token !== null) {
                $monitor = $this->apiTokenTracker->track($token, $path, $notFound);
            } else {
                $extraSignals = $invalidTokenAttempt ? [ScraperSignalDetector::SIGNAL_INVALID_API_TOKEN] : [];
                $monitor = $this->anonymousTracker->track($request, $path, $userAgent, $ip, $notFound, $extraSignals);
            }

            ApiVisitRecorder::record($monitor, $path, $ip);
        } catch (Exception $e) {
            Log::error('Monitor Package Error: '.$e->getMessage());
        }

        return $response;
    }

    /**
     * Item 7: `laravel/sanctum` é dependência OPCIONAL — isolado num único
     * método (nunca inline em `handle()`) pra ser o único ponto de
     * `class_exists()` e o único seam que os testes de "degrada sem
     * Sanctum" precisam sobrescrever (subclasse anônima retornando
     * `false` aqui, sem precisar desinstalar o pacote de verdade).
     */
    protected function sanctumAvailable(): bool
    {
        return class_exists(PersonalAccessToken::class);
    }

    /**
     * Resolve o token Sanctum DIRETO (`PersonalAccessToken::findToken()`),
     * nunca via `Auth::guard('sanctum')`/`$request->user()` — não depende
     * da rota do host declarar `auth:sanctum` (ver docblock da classe).
     * `findToken()` por si só não checa expiração (isso normalmente é
     * responsabilidade do guard do Sanctum, que não estamos usando aqui),
     * então a checagem de `expires_at` é feita manualmente — um token
     * expirado deve contar como "sem token válido" (fallback por IP),
     * nunca ser tratado como válido só porque o hash bateu.
     *
     * Só chamado quando `sanctumAvailable()` já é `true` — nunca toca
     * `Laravel\Sanctum\*` fora daqui.
     */
    protected function resolveSanctumToken(string $plainTextToken): ?object
    {
        $accessToken = PersonalAccessToken::findToken($plainTextToken);

        if (! $accessToken) {
            return null;
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return null;
        }

        return $accessToken;
    }

    /**
     * Item 5: grava `monitor_api_block_events` — "request bloqueada com
     * token válido". Fail-open (mesmo padrão do resto do pacote): se a
     * tabela ainda não existir, não pode impedir a resposta 403 que já
     * foi decidida antes de chamar isto.
     */
    protected function recordValidTokenBlockEvent(object $token, string $ip): void
    {
        try {
            MonitorApiBlockEvent::create([
                'ip' => $ip,
                'token_id' => (int) $token->getKey(),
                'token_name' => $token->name ?? null,
                'user_id' => $token->tokenable_id ?? null,
                'message' => 'request bloqueada com token válido',
            ]);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_api_block_events não encontrada — rode `php artisan migrate` ou `php artisan monitor:update`. Erro original: '.$e->getMessage());
        }
    }
}
