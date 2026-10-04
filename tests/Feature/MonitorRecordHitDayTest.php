<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 286 (v0.60.0): `Monitor::recordHit()` agora upserta por
 * `(monitor_id, path, day)` em vez de `(monitor_id, path)` — mesmo
 * visitante+path no MESMO dia soma na mesma linha; dia diferente cria uma
 * linha nova.
 */
class MonitorRecordHitDayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_same_day_hits_sum_into_one_row(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 9, 0, 0, 'UTC'));
        $monitor = Monitor::create(['data' => []]);

        $monitor->recordHit('example.com/a');

        Carbon::setTestNow(Carbon::create(2026, 10, 4, 18, 0, 0, 'UTC'));
        $monitor->recordHit('example.com/a');

        $this->assertSame(1, DB::table('monitor_page_hits')->where('monitor_id', $monitor->id)->count());

        $row = DB::table('monitor_page_hits')->where('monitor_id', $monitor->id)->first();
        $this->assertSame(2, (int) $row->hits);
        $this->assertSame('2026-10-04', $row->day);
    }

    public function test_different_day_creates_a_new_row(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 9, 0, 0, 'UTC'));
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordHit('example.com/a');

        Carbon::setTestNow(Carbon::create(2026, 10, 5, 9, 0, 0, 'UTC'));
        $monitor->recordHit('example.com/a');

        $this->assertSame(2, DB::table('monitor_page_hits')->where('monitor_id', $monitor->id)->count());

        $hitsByDay = DB::table('monitor_page_hits')
            ->where('monitor_id', $monitor->id)
            ->pluck('hits', 'day');

        $this->assertSame(1, (int) $hitsByDay['2026-10-04']);
        $this->assertSame(1, (int) $hitsByDay['2026-10-05']);
    }

    public function test_not_found_sticks_to_true_within_the_same_day_but_not_across_days(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 9, 0, 0, 'UTC'));
        $monitor = Monitor::create(['data' => []]);

        $monitor->recordHit('example.com/missing', true);
        $monitor->recordHit('example.com/missing', false);

        $row = DB::table('monitor_page_hits')->where('day', '2026-10-04')->first();
        $this->assertTrue((bool) $row->not_found, 'not_found deve continuar true no mesmo dia mesmo depois de um hit sem 404');

        Carbon::setTestNow(Carbon::create(2026, 10, 5, 9, 0, 0, 'UTC'));
        $monitor->recordHit('example.com/missing', false);

        $newRow = DB::table('monitor_page_hits')->where('day', '2026-10-05')->first();
        $this->assertFalse((bool) $newRow->not_found, 'dia novo começa do zero, não herda not_found do dia anterior');
    }

    public function test_different_monitors_same_path_same_day_get_separate_rows(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 9, 0, 0, 'UTC'));
        $a = Monitor::create(['data' => []]);
        $b = Monitor::create(['data' => []]);

        $a->recordHit('example.com/a');
        $b->recordHit('example.com/a');

        $this->assertSame(2, DB::table('monitor_page_hits')->where('path', 'example.com/a')->count());
    }
}
