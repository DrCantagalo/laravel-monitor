<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Console\Command;

/**
 * Backfill pra instalações atualizando de antes da task 135: `data.visits`
 * inflava a cada request de uma sessão já rastreada em vez de só em sessão
 * nova, então qualquer linha `Monitor` existente carrega um valor errado.
 * `data.sessions` sempre foi deduplicado corretamente (não afetado pelo
 * bug), então `count(data.sessions)` já é o valor certo de `visits` pra
 * qualquer linha existente — recalcula em lote (chunked, nunca
 * `::all()`/`cursor()` sobre a tabela toda de uma vez, mesmo padrão de
 * `DataPruner::pruneMonitors`). Deve ser rodado uma vez, manualmente, em
 * cada installation depois do `composer update` pra v0.28.0+ (ver README).
 */
class MonitorRecalculateVisitsCommand extends Command
{
    protected $signature = 'monitor:recalculate-visits';

    protected $description = 'Recalculate Monitor.data.visits from data.sessions to backfill rows inflated by the pre-135 counting bug';

    public function handle(): int
    {
        $updated = 0;

        Monitor::select('id', 'data')
            ->chunkById(200, function ($monitors) use (&$updated) {
                foreach ($monitors as $monitor) {
                    $correctVisits = count((array) data_get($monitor, 'data.sessions', []));
                    $data = $monitor->data;

                    if (($data['visits'] ?? null) === $correctVisits) {
                        continue;
                    }

                    $data['visits'] = $correctVisits;
                    $monitor->data = $data;
                    $monitor->save();
                    $updated++;
                }
            });

        $this->info("Recalculated visits for {$updated} monitor row(s).");

        return 0;
    }
}
