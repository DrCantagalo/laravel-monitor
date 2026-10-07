<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Drcantagalo\LaravelMonitor\Support\DataPruner;
use Drcantagalo\LaravelMonitor\Support\MonitorCategories;
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
 *
 * laravel-monitor 308 (v0.63.0): `--category=` (repetível) restringe o
 * delete a um subconjunto de `MonitorCategories::ALL` — mesma regra da
 * action HTTP `clearData`. Mutuamente exclusivo com `--only-blocked`
 * (eixos de filtro diferentes, combinação não suportada). Com
 * `--category`, `monitor_ip_stats`/`monitor_visits`/`monitor_page_hits`
 * só são podados quando TODAS as categorias estão selecionadas — ver
 * `DataPruner::pruneByCategory()`.
 */
class MonitorPruneCommand extends Command
{
    protected $signature = 'monitor:prune {--only-blocked} {--older-than-days=} {--category=* : Restrict the delete to a subset of MonitorCategories::ALL (repeatable); default is all categories, same as before this option existed}';

    protected $description = 'Prune Monitor/monitor_ip_stats tracking data older than a cutoff, optionally restricted to confirmed-blocked IPs and/or a subset of Monitor categories';

    public function handle(): int
    {
        $olderThanDays = $this->option('older-than-days');

        if (! is_numeric($olderThanDays) || (int) $olderThanDays < 0) {
            $this->error('--older-than-days is required and must be a non-negative integer.');

            return 1;
        }

        $onlyBlocked = (bool) $this->option('only-blocked');
        $categories = array_values(array_unique($this->option('category')));

        // laravel-monitor 308 (v0.63.0): `--category` reusa a MESMA regra
        // de categorização da action HTTP `clearData`
        // (`Support\MonitorCategories`/`DataPruner::pruneByCategory()`),
        // nunca uma segunda implementação. Sem `--category` (o default,
        // array vazio), o comportamento é byte a byte o de antes desta
        // task — `DataPruner::prune()` abaixo, inalterado.
        if (! empty($categories)) {
            if ($onlyBlocked) {
                $this->error('--category cannot be combined with --only-blocked (they filter along different axes — IP reputation vs. Monitor classification — and combining them is not supported).');

                return 1;
            }

            foreach ($categories as $category) {
                if (! in_array($category, MonitorCategories::ALL, true)) {
                    $this->error("Invalid --category value: {$category}. Allowed: ".implode(', ', MonitorCategories::ALL));

                    return 1;
                }
            }

            $result = DataPruner::pruneByCategory((int) $olderThanDays, $categories);

            $this->info("Pruned {$result['monitors_deleted']} monitor row(s) matching --category=".implode(',', $categories)." (older-than-days={$olderThanDays}). monitor_ip_stats/monitor_visits/monitor_page_hits are untouched unless every category was selected.");

            return 0;
        }

        $result = DataPruner::prune((int) $olderThanDays, $onlyBlocked);

        // laravel-monitor 152 (v0.46.0): visits_deleted agora soma duas
        // fontes independentes — a retenção configurável
        // (monitor.visits_retention_days, 0 = desligada por padrão) e,
        // só quando --only-blocked NÃO foi passado, um segundo varrimento
        // por --older-than-days (ver DataPruner::prune()). A mensagem
        // reflete os dois sem assumir qual deles (se algum) de fato gerou
        // as linhas apagadas.
        $visitsNote = $onlyBlocked
            ? 'past monitor.visits_retention_days'
            : 'past monitor.visits_retention_days and/or --older-than-days';

        // laravel-monitor 286 (v0.60.0): page_hits_deleted segue a MESMA
        // retenção de visits_retention_days (nunca --older-than-days),
        // reportado separado por clareza (não é visita, é perfil de
        // navegação por path).
        $this->info("Pruned {$result['monitors_deleted']} monitor row(s), {$result['ip_stats_deleted']} IP stat row(s), {$result['visits_deleted']} visit row(s) {$visitsNote}, and {$result['page_hits_deleted']} page hit row(s) past monitor.visits_retention_days.");

        return 0;
    }
}
