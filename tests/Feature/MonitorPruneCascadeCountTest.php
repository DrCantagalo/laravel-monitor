<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Support\DataPruner;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 317 — bugs/laravel-monitor.md (2026-10-07):
 * `DataPruner::prune()` subcontava `visits_deleted`/`page_hits_deleted`
 * quando o Monitor dono também era podado no mesmo corte — o
 * `ON DELETE CASCADE` já removia essas linhas antes de qualquer contagem
 * explícita (`deleteVisitsOlderThan()`/`prunePageHits()`) conseguir
 * encontrá-las. As linhas certas sempre foram apagadas; só o número
 * relatado ficava errado. Reproduz o cenário exato do bug e confirma a
 * correção.
 */
class MonitorPruneCascadeCountTest extends TestCase
{
    use RefreshDatabase;

    protected function seedOldMonitorWithVisitAndPageHit(): Monitor
    {
        $monitor = Monitor::create(['data' => []]);

        $old = Carbon::now()->subDays(30);

        $monitor->timestamps = false;
        $monitor->updated_at = $old;
        $monitor->save();

        DB::table('monitor_visits')->insert([
            'monitor_id' => $monitor->id,
            'paths' => json_encode(['a']),
            'scraper' => false,
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'hits' => 1,
            'not_found' => false,
            'day' => $old->toDateString(),
            'created_at' => $old,
            'updated_at' => $old,
        ]);

        return $monitor;
    }

    public function test_cascaded_visit_and_page_hit_are_counted_when_owning_monitor_is_pruned_in_the_same_cutoff(): void
    {
        $monitor = $this->seedOldMonitorWithVisitAndPageHit();

        $result = DataPruner::prune(1, false);

        // As linhas sempre foram apagadas (via cascade) — a correção é só
        // sobre o NÚMERO relatado passar a refletir isso.
        $this->assertSame(0, Monitor::count());
        $this->assertSame(0, DB::table('monitor_visits')->count());
        $this->assertSame(0, DB::table('monitor_page_hits')->count());

        $this->assertSame(1, $result['monitors_deleted']);
        $this->assertSame(1, $result['visits_deleted'], 'visit cascadeada pelo delete do Monitor dono precisa ser contada');
        $this->assertSame(1, $result['page_hits_deleted'], 'page_hit cascadeado pelo delete do Monitor dono precisa ser contado');
    }

    public function test_cascaded_rows_are_not_double_counted_with_an_independent_visits_retention_match(): void
    {
        config(['monitor.visits_retention_days' => 1]);

        // Um segundo Monitor, SEM corte de idade (updated_at recente, não
        // elegível pra pruneMonitors), mas com visit/page_hit antigos o
        // bastante pra caírem na retenção independente
        // (visits_retention_days) — tem que ser contado uma vez só, pela
        // via explícita (deleteVisitsOlderThan/prunePageHits), nunca pela
        // via cascade (o Monitor dele não foi apagado).
        $survivingMonitor = Monitor::create(['data' => []]);
        $oldHit = Carbon::now()->subDays(30);

        DB::table('monitor_visits')->insert([
            'monitor_id' => $survivingMonitor->id,
            'paths' => json_encode(['b']),
            'scraper' => false,
            'created_at' => $oldHit,
            'updated_at' => $oldHit,
        ]);

        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $survivingMonitor->id,
            'path' => 'example.com/b',
            'hits' => 1,
            'not_found' => false,
            'day' => $oldHit->toDateString(),
            'created_at' => $oldHit,
            'updated_at' => $oldHit,
        ]);

        $prunedMonitor = $this->seedOldMonitorWithVisitAndPageHit();

        $result = DataPruner::prune(1, false);

        $this->assertSame(1, $result['monitors_deleted']);
        $this->assertSame(2, $result['visits_deleted'], '1 cascadeada + 1 pela retenção independente, sem dupla contagem');
        $this->assertSame(2, $result['page_hits_deleted'], '1 cascadeado + 1 pela retenção independente, sem dupla contagem');

        $this->assertSame(0, DB::table('monitor_visits')->count());
        $this->assertSame(0, DB::table('monitor_page_hits')->count());
    }

    public function test_only_blocked_true_path_also_counts_cascaded_rows(): void
    {
        $ip = '9.9.9.9';
        $monitor = $this->seedOldMonitorWithVisitAndPageHit();
        $monitor->recordIp($ip);

        BlockedIp::create(['ip' => $ip, 'source' => 'manual']);

        $result = DataPruner::prune(1, true);

        $this->assertSame(1, $result['monitors_deleted']);
        $this->assertSame(1, $result['visits_deleted']);
        $this->assertSame(1, $result['page_hits_deleted']);
    }

    public function test_max_rows_capped_path_also_counts_cascaded_rows(): void
    {
        // onlyBlocked=true (caminho do gatilho automático, único que
        // passa $maxRows de verdade) — ao contrário de onlyBlocked=false,
        // não tem a varredura independente de monitor_visits por
        // $olderThanDays (deleteVisitsOlderThan só roda quando
        // onlyBlocked=false), então o único visits_deleted/page_hits_deleted
        // esperado aqui vem mesmo da cascata do Monitor podado, isolando
        // só o que esta task corrige.
        $ip = '9.9.9.1';

        $monitorA = $this->seedOldMonitorWithVisitAndPageHit();
        $monitorA->recordIp($ip);

        $monitorB = $this->seedOldMonitorWithVisitAndPageHit();
        $monitorB->recordIp($ip);

        \Drcantagalo\LaravelMonitor\Models\BlockedIp::create(['ip' => $ip, 'source' => 'manual']);

        $result = DataPruner::prune(1, true, 1);

        $this->assertSame(1, $result['monitors_deleted']);
        $this->assertSame(1, $result['visits_deleted']);
        $this->assertSame(1, $result['page_hits_deleted']);
        $this->assertFalse($result['done'], 'ainda sobrou 1 monitor elegível além do teto de 1 por execução');
    }
}
