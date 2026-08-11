<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorMethod
{
    public function __construct(
        protected SessionVisitorTracker $sessionTracker,
        protected AnonymousVisitorTracker $anonymousTracker,
    ) {
    }

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
        $path = $request->getHost() . '/' . ltrim($request->path(), '/');
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        // IP bloqueado (via updateBlockedIps): corta o request aqui, antes
        // de qualquer tracking/detecção. Checado antes de tudo (inclusive
        // requests com sessão) — bloqueio vale pra qualquer origem.
        //
        // Fail-open: se a tabela monitor_blocked_ips ainda não existir
        // (composer require feito mas migrations ainda não rodaram), essa
        // query não pode derrubar o site inteiro do cliente. abort(403)
        // fica FORA do try pra não ser engolido pelo catch.
        try {
            $blocked = $this->isBlocked($ip);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_blocked_ips não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: ' . $e->getMessage());
            $blocked = false;
        }

        if ($blocked) {
            abort(403);
        }

        // 2. PROCESSAMENTO (O Laravel segue para os outros middlewares e para o Controller)
        $response = $next($request);

        // 3. LÓGICA DE "VOLTA" (Agora a Session já está disponível!)
        try {
            if ($request->hasSession()) {
                $this->sessionTracker->track($request, $response, $path, $userAgent, $ip);
            } else {
                $this->anonymousTracker->track($request, $path, $userAgent, $ip);
            }
        } catch (Exception $e) {
            Log::error("Monitor Package Error: " . $e->getMessage());
        }

        return $response;
    }

    /**
     * Confere se o IP está na blocklist (`monitor_blocked_ips`), cacheado
     * por `monitor.blocked_ip_cache_ttl` segundos pra evitar uma query por
     * request. Cache é invalidado em `updateBlockedIps` ao adicionar um
     * IP novo.
     */
    protected function isBlocked(string $ip): bool
    {
        return Cache::remember(
            "monitor:blocked-ip:{$ip}",
            (int) config('monitor.blocked_ip_cache_ttl', 60),
            fn () => BlockedIp::where('ip', $ip)->exists()
        );
    }
}
