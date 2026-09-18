<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cleanup parcial de dados de tracking (`Monitor`/`monitor_ip_stats`) mais
 * antigos que um cutoff, opcionalmente restrito aos IPs confirmado-
 * bloqueados (`monitor_blocked_ips`) — extraído de
 * `MonitorController::pruneData()`/`pruneMonitors()` (task 134) pra ficar
 * reusável fora do controller, pelo comando `monitor:prune` além da rota
 * HTTP `pruneData` existente (comportamento/response desta última não
 * mudam).
 *
 * Motivação original do `only_blocked`: manter o histórico de tracking de
 * um IP já confirmado como scraper não tem valor, e o volume de dados
 * cresce rápido por causa deles — sem isso, só existia o cutoff manual por
 * idade (`older_than_days`), sem meio de purgar automaticamente assim que
 * um IP é bloqueado.
 */
class DataPruner
{
    protected const CACHE_KEY = 'monitor:data-prune:last-run';

    /**
     * Gatilho automático (laravel-monitor 141), sem depender de cron do
     * consumidor: `monitor:prune` só limpava se alguém configurasse
     * `Schedule::command(...)->hourly()` por conta própria — opt-in,
     * documentado no README mas a maioria nunca faz, então o tracking de
     * IPs já bloqueados crescia pra sempre em silêncio. Mesmo padrão
     * determinístico (timestamp em cache, não probabilístico) já usado por
     * `BlockedIpCleaner::maybeCleanup()` (task 97) — chamado a cada
     * request rastreada pelos dois trackers, só executa de fato se já
     * passou `config('monitor.data_prune_interval_hours')` desde a última
     * vez. Cache key própria, não reusa a do `BlockedIpCleaner` (intervalos
     * configuráveis independentemente). A query agora é indexada
     * (`pruneMonitors()` via `monitor_visit_ips`, ver acima), então rodar
     * isso inline no caminho de request é seguro, sem precisar de queue.
     */
    public static function maybeCleanup(): void
    {
        $intervalHours = (int) config('monitor.data_prune_interval_hours', 1);
        $lastRunAt = Cache::get(self::CACHE_KEY);

        if ($lastRunAt !== null && (time() - $lastRunAt) < $intervalHours * 3600) {
            return;
        }

        self::prune(0, true);

        Cache::forever(self::CACHE_KEY, time());
    }

    public static function prune(int $olderThanDays, bool $onlyBlocked): array
    {
        $cutoff = now()->subDays($olderThanDays);

        $monitorsDeleted = self::pruneMonitors($cutoff, $onlyBlocked);

        $ipStatQuery = IpStat::where('last_seen', '<', $cutoff);

        if ($onlyBlocked) {
            $ipStatQuery->whereIn('ip', BlockedIp::pluck('ip'));
        }

        $ipStatsDeleted = $ipStatQuery->delete();

        if ($monitorsDeleted > 0) {
            self::invalidatePagesCache();
        }

        if ($ipStatsDeleted > 0) {
            ListingsCache::invalidate();
        }

        return [
            'monitors_deleted' => $monitorsDeleted,
            'ip_stats_deleted' => $ipStatsDeleted,
        ];
    }

    /**
     * Sem `only_blocked`, o delete é direto em SQL (bulk, sem carregar
     * nada em PHP). Com `only_blocked=true` (laravel-monitor 141): antes
     * fazia o mesmo contorno de `buildPagesResult` pré-103 — abria
     * `data.ips` (blob JSON) em chunks de 200 pra achar interseção com IP
     * bloqueado em PHP, sem índice. `monitor_visit_ips` (task 104, já
     * mantida em sincronia a cada save de `Monitor`) resolve o mesmo
     * mapeamento IP->Monitor via índice em `ip`, então o join agora é uma
     * query indexada só — ainda respeitando o cutoff, não muda o
     * comportamento de `--older-than-days`, só fica mais rápido por
     * dentro.
     */
    public static function pruneMonitors(Carbon $cutoff, bool $onlyBlocked): int
    {
        if (! $onlyBlocked) {
            return Monitor::where('updated_at', '<', $cutoff)->delete();
        }

        $blockedIps = BlockedIp::pluck('ip');

        if ($blockedIps->isEmpty()) {
            return 0;
        }

        $ids = DB::table('monitor_visit_ips')
            ->whereIn('ip', $blockedIps)
            ->distinct()
            ->pluck('monitor_id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return Monitor::whereIn('id', $ids)->where('updated_at', '<', $cutoff)->delete();
    }

    /**
     * Mesmo contador de versão usado por `getPages`
     * (`MonitorController::buildPagesResult`, chave `monitor:pages:version`)
     * — incrementar invalida todo cache de páginas em uma escrita O(1), sem
     * precisar varrer/apagar chaves individuais.
     */
    private static function invalidatePagesCache(): void
    {
        if (! Cache::has('monitor:pages:version')) {
            Cache::forever('monitor:pages:version', 1);
        }

        Cache::increment('monitor:pages:version');
    }
}
