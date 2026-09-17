<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
     * nada em PHP). Com `only_blocked=true`, precisa do mesmo contorno de
     * `buildPagesResult`: `data.ips` mora dentro do blob JSON de
     * `Monitor.data`, sem coluna própria pra filtrar de forma portável
     * entre sqlite/mysql/pgsql — então junta os ids em PHP via chunk e só
     * deleta ao final (nunca durante o chunk). Match por IP confirmado-
     * bloqueado (mesmo estilo de `flagScraperPath`/`buildVisitorsResult`,
     * que já resolvem `monitor_blocked_ips` numa query só e testam contra
     * ela em vez de um JOIN de verdade).
     */
    public static function pruneMonitors(Carbon $cutoff, bool $onlyBlocked): int
    {
        if (! $onlyBlocked) {
            return Monitor::where('updated_at', '<', $cutoff)->delete();
        }

        $blockedIps = BlockedIp::pluck('ip')->all();

        if (empty($blockedIps)) {
            return 0;
        }

        $ids = [];

        Monitor::where('updated_at', '<', $cutoff)
            ->select('id', 'data')
            ->chunkById(200, function ($monitors) use ($blockedIps, &$ids) {
                foreach ($monitors as $monitor) {
                    $ips = (array) data_get($monitor, 'data.ips', []);

                    if (array_intersect($ips, $blockedIps)) {
                        $ids[] = $monitor->id;
                    }
                }
            });

        if (empty($ids)) {
            return 0;
        }

        return Monitor::whereIn('id', $ids)->delete();
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
