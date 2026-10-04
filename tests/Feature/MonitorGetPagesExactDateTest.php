<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 286 (v0.60.0): `getPages`' `date_from`/`date_to` agora
 * filtram exatamente pela coluna `day` de `monitor_page_hits` — antes
 * filtravam por `monitors.updated_at` (última atividade do VISITANTE
 * inteiro), somando os hits da vida toda dele mesmo fora da janela pedida.
 */
class MonitorGetPagesExactDateTest extends TestCase
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

    public function test_date_from_date_to_filter_by_exact_day_not_by_monitor_lifetime(): void
    {
        $monitor = Monitor::create(['data' => []]);

        // mesmo visitante, hits em dias bem distantes entre si — o schema
        // antigo (filtro por monitors.updated_at) somaria os dois juntos
        // sempre que a janela tocasse a ÚLTIMA atividade do Monitor.
        $this->hitOnDay($monitor, '2026-01-01');
        $this->hitOnDay($monitor, '2026-10-04');

        $response = $this->callHandler([
            'action' => 'getPages',
            'filter' => 'all',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame(1, $data[0]['hits'], 'só o hit de 2026-10-04 deve entrar na janela, não o de 2026-01-01');
    }

    public function test_date_from_at_midnight_includes_hits_from_that_same_day(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->hitOnDay($monitor, '2026-10-04');

        $response = $this->callHandler([
            'action' => 'getPages',
            'filter' => 'all',
            'date_from' => '2026-10-04 00:00:00',
            'date_to' => '2026-10-04 23:59:59',
        ]);

        $response->assertOk();
        $this->assertCount(
            1,
            $response->json('data'),
            'date_from à meia-noite não pode excluir o próprio dia (comparação lexicográfica no SQLite entre DATE e datetime)'
        );
    }

    public function test_hits_outside_the_window_are_excluded_entirely(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->hitOnDay($monitor, '2026-01-01');

        $response = $this->callHandler([
            'action' => 'getPages',
            'filter' => 'all',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_exact_since_is_exposed_and_matches_migration_settings(): void
    {
        $exactSince = DB::table('monitor_settings')->where('key', 'page_hits_exact_since')->value('value');

        $response = $this->callHandler(['action' => 'getPages', 'filter' => 'all']);

        $response->assertOk();
        $response->assertJsonPath('exact_since', $exactSince);
    }
}
