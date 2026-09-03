<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\BlockedPath;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorMethod
{
    public function __construct(
        protected SessionVisitorTracker $sessionTracker,
        protected AnonymousVisitorTracker $anonymousTracker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. LÓGICA DE "IDA"
        if (app()->runningInConsole()) {
            return $next($request);
        }

        // Capturamos dados básicos antes do processamento
        //
        // Prefixamos o path com o host: sites multidominio/multisubdominio
        // que compartilham a mesma instalação do pacote perdiam essa
        // informação (ex: "/dashboard/3/blacklist" não dizia se veio de
        // "app.exemplo.com" ou "admin.exemplo.com").
        $pathOnly = ltrim($request->path(), '/');
        $path = $request->getHost().'/'.$pathOnly;
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        // IP bloqueado (via updateBlockedIps) ou path flagado como scrapper
        // (via flagScraperPath): corta o request aqui, antes de qualquer
        // tracking/detecção. Checado antes de tudo (inclusive requests com
        // sessão) — bloqueio vale pra qualquer origem. Path é checado sem o
        // host: uma installation pode atender vários subdomínios (ver
        // comentário acima sobre o prefixo de host em `data.page`), e o
        // flag deve proteger todos eles.
        //
        // Fail-open: se as tabelas ainda não existirem (composer require
        // feito mas migrations ainda não rodaram), essa query não pode
        // derrubar o site inteiro do cliente. abort(403) fica FORA do try
        // pra não ser engolido pelo catch.
        try {
            $blocked = $this->isBlocked($ip) || $this->isPathBlocked($pathOnly);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_blocked_ips ou monitor_blocked_paths não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
            $blocked = false;
        }

        if ($blocked) {
            $this->recordBlockedAttempt($ip);
            abort(403);
        }

        // 2. PROCESSAMENTO (O Laravel segue para os outros middlewares e para o Controller)
        $response = $next($request);

        // 3. LÓGICA DE "VOLTA" (Agora a Session já está disponível!)
        try {
            $notFound = $response->getStatusCode() === 404;

            if ($request->hasSession()) {
                $this->sessionTracker->track($request, $response, $path, $userAgent, $ip, $notFound);
            } else {
                $this->anonymousTracker->track($request, $path, $userAgent, $ip, $notFound);
            }
        } catch (Exception $e) {
            Log::error('Monitor Package Error: '.$e->getMessage());
        }

        return $response;
    }

    /**
     * Confere se o path (sem host) foi flagado como scrapper (via
     * flagScraperPath), cacheado como `isBlocked()` acima.
     */
    protected function isPathBlocked(string $path): bool
    {
        return Cache::remember(
            "monitor:blocked-path:{$path}",
            (int) config('monitor.blocked_ip_cache_ttl', 60),
            fn () => BlockedPath::where('path', $path)->exists()
        );
    }

    /**
     * Confere se o IP está na blocklist (`monitor_blocked_ips`), cacheado
     * por `monitor.blocked_ip_cache_ttl` segundos pra evitar uma query por
     * request. Cache é invalidado em `updateBlockedIps` ao adicionar um
     * IP novo.
     *
     * `blocked_until` null = permanente (bloqueio manual, ou automático já
     * escalado a permanente — ver `ScraperBlocker::registerOffense`);
     * um `blocked_until` no passado não conta mais como bloqueado. Como o
     * TTL do cache acima já é curto por padrão (60s), a expiração natural
     * do cache garante que um bloqueio expirado some da aplicação nesse
     * intervalo, sem precisar de nenhum job/cron dedicado.
     */
    protected function isBlocked(string $ip): bool
    {
        return Cache::remember(
            "monitor:blocked-ip:{$ip}",
            (int) config('monitor.blocked_ip_cache_ttl', 60),
            fn () => BlockedIp::where('ip', $ip)
                ->where(fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '>', now()))
                ->exists()
        );
    }

    /**
     * Incrementa o contador de tentativas bloqueadas desse IP em
     * `monitor_block_results` (task 83) — chamado logo antes do
     * `abort(403)` acima, cobrindo os dois motivos de bloqueio de uma vez
     * só: `$blocked` já é o OR de `isBlocked()`/`isPathBlocked()`, então
     * não importa qual dos dois disparou — o IP da request atual é quem
     * toma o 403 e é quem conta aqui, inclusive um IP nunca antes visto
     * batendo num path já flagado como honeypot (nunca esteve em
     * `monitor_blocked_ips` por si só).
     *
     * Upsert atômico via query builder (uma query, sem race entre um
     * SELECT+UPDATE/INSERT concorrentes do mesmo IP martelando o mesmo
     * endpoint bloqueado) — `upsert()` gera o SQL correto pro driver ativo
     * (`ON DUPLICATE KEY UPDATE` no MySQL, `ON CONFLICT` no SQLite/Postgres),
     * mesmo motivo de escolha já documentado pros outros upserts do
     * pacote (`IpStat::recordVisit`).
     *
     * Fail-open: se `monitor_block_results` ainda não existir (migration
     * não rodou), essa query não pode derrubar o bloqueio em si —
     * `abort(403)` roda de qualquer jeito, fora deste método, mesmo se o
     * catch abaixo disparar.
     */
    protected function recordBlockedAttempt(string $ip): void
    {
        try {
            DB::table('monitor_block_results')->upsert(
                ['ip' => $ip, 'counter' => 1, 'last_attempt_at' => now()],
                ['ip'],
                ['counter' => DB::raw('counter + 1'), 'last_attempt_at' => now()]
            );
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_block_results não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
        }
    }
}
