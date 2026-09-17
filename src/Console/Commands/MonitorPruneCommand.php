<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Drcantagalo\LaravelMonitor\Support\DataPruner;
use Illuminate\Console\Command;

/**
 * Equivalente via artisan da rota HTTP `pruneData` (task 134) — pra rodar
 * via cron do app consumidor em vez de exigir uma request manual. Uso
 * recomendado em produção: `monitor:prune --only-blocked
 * --older-than-days=0` com alguma frequência (ex: hourly) — um IP já
 * confirmado/bloqueado nunca reaparece nesse filtro, então rodar com
 * frequência é barato e mantém baixo o atraso entre bloqueio e purga do
 * histórico de tracking daquele IP (motivação: volume de dados cresce
 * rápido por causa de scrapers, e reter o tracking de um IP já confirmado
 * não tem valor).
 */
class MonitorPruneCommand extends Command
{
    protected $signature = 'monitor:prune {--only-blocked} {--older-than-days=}';

    protected $description = 'Prune Monitor/monitor_ip_stats tracking data older than a cutoff, optionally restricted to confirmed-blocked IPs';

    public function handle(): int
    {
        $olderThanDays = $this->option('older-than-days');

        if (! is_numeric($olderThanDays) || (int) $olderThanDays < 0) {
            $this->error('--older-than-days is required and must be a non-negative integer.');

            return 1;
        }

        $result = DataPruner::prune((int) $olderThanDays, (bool) $this->option('only-blocked'));

        $this->info("Pruned {$result['monitors_deleted']} monitor row(s) and {$result['ip_stats_deleted']} IP stat row(s).");

        return 0;
    }
}
