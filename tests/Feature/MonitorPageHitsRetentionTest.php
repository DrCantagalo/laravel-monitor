<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Support\DataPruner;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 286 (v0.60.0): `DataPruner::prune()` agora também apaga
 * `monitor_page_hits` com `day` mais antigo que `monitor.visits_retention_days`
 * — mesmo cutoff/gate de `pruneVisits()` (`0` = desligado, default). Antes
 * desta task `monitor_page_hits` não tinha retenção própria nenhuma.
 */
class MonitorPageHitsRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function hitOnDay(Monitor $monitor, string $day, string $path = 'example.com/a'): void
    {
        Carbon::setTestNow(Carbon::parse($day.' 12:00:00'));
        $monitor->recordHit($path);
    }

    public function test_default_retention_zero_never_deletes_page_hits(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->hitOnDay($monitor, '2020-01-01');

        Carbon::setTestNow(Carbon::create(2026, 10, 4));

        $result = DataPruner::prune(0, false);

        $this->assertSame(0, $result['page_hits_deleted']);
        $this->assertSame(1, DB::table('monitor_page_hits')->count());
    }

    public function test_retention_deletes_page_hits_older_than_cutoff_regardless_of_only_blocked(): void
    {
        config(['monitor.visits_retention_days' => 30]);

        $monitor = Monitor::create(['data' => []]);
        $this->hitOnDay($monitor, '2026-01-01');
        $this->hitOnDay($monitor, '2026-10-01');

        Carbon::setTestNow(Carbon::create(2026, 10, 4));

        // mesmo com $onlyBlocked=true (caminho do gatilho automático), a
        // retenção de monitor_page_hits roda — mesmo comportamento de
        // pruneVisits() já estabelecido.
        $result = DataPruner::prune(0, true);

        $this->assertSame(1, $result['page_hits_deleted']);
        $this->assertSame(1, DB::table('monitor_page_hits')->count());
        $this->assertSame('2026-10-01', DB::table('monitor_page_hits')->value('day'));
    }

    public function test_covers_the_anonymous_tracker_which_never_creates_a_visit(): void
    {
        // Monitor sem nenhuma monitor_visits — simula o tracker anônimo
        // (bots/API, sem sessão) que nunca cria linha em monitor_visits,
        // então pruneVisits() sozinho não limpa o histórico dele.
        config(['monitor.visits_retention_days' => 1]);

        $monitor = Monitor::create(['data' => []]);
        $this->hitOnDay($monitor, '2020-01-01');

        $this->assertSame(0, \Drcantagalo\LaravelMonitor\Models\MonitorVisit::count());

        Carbon::setTestNow(Carbon::create(2026, 10, 4));

        DataPruner::prune(0, false);

        $this->assertSame(0, DB::table('monitor_page_hits')->count());
    }

    public function test_page_hits_count_is_reported_separately_from_visits_deleted(): void
    {
        config(['monitor.visits_retention_days' => 1]);

        $monitor = Monitor::create(['data' => []]);
        $this->hitOnDay($monitor, '2020-01-01');

        Carbon::setTestNow(Carbon::create(2026, 10, 4));

        $result = DataPruner::prune(0, false);

        $this->assertArrayHasKey('page_hits_deleted', $result);
        $this->assertSame(1, $result['page_hits_deleted']);
        $this->assertSame(0, $result['visits_deleted'], 'não existia monitor_visits pra apagar, page_hits_deleted não deve inflar visits_deleted');
    }
}
