<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware\Concerns;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Support\ScraperBlocker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * laravel-monitor 400: checagem de bloqueio "na ida" (ignored-IPs +
 * enforcement de IP/path bloqueado), extraída de `MonitorMethod` pra ser
 * compartilhada com `MonitorApiMethod` (grupo `api`) — as duas precisam do
 * MESMO enforcement de entrada (só depende do IP/path, nunca de sessão),
 * mas cada uma tem sua própria lógica de tracking "na volta"
 * (`$next($request)`), que NÃO entra aqui de propósito (`MonitorMethod`
 * usa Session/AnonymousVisitorTracker; `MonitorApiMethod` usa
 * ApiTokenVisitorTracker/AnonymousVisitorTracker — ver docblock de cada
 * uma). Trait em vez de classe base abstrata: `MonitorMethod` já não tem
 * nenhuma classe-mãe hoje e um trait evita impor uma hierarquia de herança
 * só pra compartilhar 4 métodos puramente de leitura/registro, sem estado
 * próprio (tudo aqui é cache/banco, nenhuma propriedade de instância).
 */
trait BlocksInboundRequests
{
    /**
     * `monitor.ignore_ips`: IPs exatos ou faixas CIDR (IPv4/IPv6, via
     * `IpUtils::checkIp`). Só uma comparação em memória — não consulta banco
     * nem cache, então custa praticamente nada por request.
     */
    protected function isIgnoredIp(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $ignored = array_values(array_filter(array_map(
            fn ($entry) => trim((string) $entry),
            (array) config('monitor.ignore_ips', [])
        )));

        return $ignored !== [] && IpUtils::checkIp($ip, $ignored);
    }

    /**
     * Confere se o path (sem host) foi flagado como scrapper (via
     * flagScraperPath), cacheado como `isBlocked()` abaixo.
     *
     * `strtolower()` antes de comparar: `monitor_paths.path` é sempre
     * gravado em minúsculo (`MonitorController::normalizePathInput()`),
     * mas o path de uma request ao vivo mantém a caixa exata que o
     * visitante mandou.
     */
    protected function isPathBlocked(string $path): bool
    {
        $path = strtolower($path);
        $query = fn () => MonitorPath::where('path', $path)->where('status', 'trap')->exists();

        try {
            return Cache::remember(
                "monitor:blocked-path:{$path}",
                (int) config('monitor.blocked_ip_cache_ttl', 60),
                $query
            );
        } catch (QueryException $e) {
            // Tabela ainda não migrada: deixa subir pro catch(QueryException)
            // de handle(), que já trata esse caso assumindo `false`.
            throw $e;
        } catch (\Throwable $e) {
            // Cache store fora do ar (Redis/Memcached indisponível, etc):
            // NÃO assume `false` aqui — deixaria passar um path que devia
            // continuar bloqueado bem na janela de instabilidade. Em vez
            // disso, consulta o banco direto, sem cache, só logando o aviso.
            Log::warning('[laravel-monitor] cache store indisponível ao checar bloqueio de path — consultando banco diretamente. Erro original: '.$e->getMessage());

            return $query();
        }
    }

    /**
     * Confere se o IP está na blocklist (`monitor_blocked_ips`), cacheado
     * por `monitor.blocked_ip_cache_ttl` segundos pra evitar uma query por
     * request. Cache é invalidado em `updateBlockedIps` ao adicionar um
     * IP novo.
     */
    protected function isBlocked(string $ip): bool
    {
        $query = fn () => BlockedIp::where('ip', $ip)->active()->exists();

        try {
            return Cache::remember(
                "monitor:blocked-ip:{$ip}",
                (int) config('monitor.blocked_ip_cache_ttl', 60),
                $query
            );
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('[laravel-monitor] cache store indisponível ao checar bloqueio de IP — consultando banco diretamente. Erro original: '.$e->getMessage());

            return $query();
        }
    }

    /**
     * Incrementa o contador de tentativas bloqueadas desse IP em
     * `monitor_block_results` (task 83) — chamado logo antes de responder
     * o bloqueio. Fail-open: se `monitor_block_results` ainda não existir
     * (migration não rodou), essa query não pode derrubar o bloqueio em si.
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

    /**
     * Registra a ofensa de honeypot (mesmo caminho usado pelas duas
     * middlewares quando um path armadilha é atingido pela primeira vez,
     * antes do IP entrar em `monitor_blocked_ips` por si só).
     */
    protected function registerHoneypotOffense(string $ip): void
    {
        try {
            (new ScraperBlocker)->registerOffense($ip, 'scraper-path');
        } catch (\Throwable $e) {
            Log::warning('[laravel-monitor] falha ao registrar ofensa automática de honeypot — Erro original: '.$e->getMessage());
        }
    }
}
