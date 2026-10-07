<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 286 (v0.60.0): migration
 * `2026_10_04_000001_redesign_monitor_page_hits_with_day` — adiciona `day`
 * a `monitor_page_hits` (unique vira `(monitor_id, path, day)`) e grava
 * `monitor_settings.page_hits_exact_since`. Mesmo padrão de
 * `MonitorLabelsUniqueMonitorIdTest`: `down()` desfaz pra simular o schema
 * ANTIGO (sem `day`, unique antigo), popula dados nesse formato, e `up()`
 * roda de novo pra exercitar o backfill contra dados reais.
 */
class MonitorPageHitsDayMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function migration(): object
    {
        return require dirname(__DIR__, 2).'/src/database/migrations/2026_10_04_000001_redesign_monitor_page_hits_with_day.php';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_up_creates_monitor_settings_and_records_exact_since(): void
    {
        // `RefreshDatabase` já rodou `up()` no `setUp()`, com o relógio
        // real — antes deste método ter a chance de congelar o tempo.
        // Por isso, mesmo padrão dos testes irmãos abaixo: `down()` +
        // `up()` de novo, agora com `setTestNow()` já congelado.
        Carbon::setTestNow(Carbon::create(2026, 10, 4, 15, 0, 0, 'UTC'));

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('monitor_settings'));
        $this->assertSame(
            '2026-10-04',
            DB::table('monitor_settings')->where('key', 'page_hits_exact_since')->value('value')
        );
    }

    public function test_up_backfills_day_from_updated_at_on_pre_existing_rows(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('monitor_page_hits', 'day'));

        $monitor = Monitor::create(['data' => []]);

        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'hits' => 5,
            'not_found' => false,
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-20 10:00:00',
        ]);

        $migration->up();

        $row = DB::table('monitor_page_hits')
            ->where('monitor_id', $monitor->id)
            ->where('path', 'example.com/a')
            ->first();

        $this->assertSame('2026-09-20', $row->day);
        $this->assertSame(5, (int) $row->hits);
    }

    public function test_up_recreates_unique_constraint_including_day(): void
    {
        $migration = $this->migration();
        $migration->down();

        $monitor = Monitor::create(['data' => []]);

        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'hits' => 1,
            'not_found' => false,
            'created_at' => now(),
            'updated_at' => '2026-09-20 10:00:00',
        ]);

        $migration->up();

        // mesmo monitor+path, dia DIFERENTE do backfillado (2026-09-20) —
        // não deve colidir com o unique novo (inclui `day`).
        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'day' => '2026-10-04',
            'hits' => 1,
            'not_found' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('monitor_page_hits')->where('monitor_id', $monitor->id)->count());

        $this->expectException(UniqueConstraintViolationException::class);

        // mesmo monitor+path+dia (2026-10-04 de novo) — agora deve colidir.
        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'day' => '2026-10-04',
            'hits' => 1,
            'not_found' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_down_drops_day_column_monitor_settings_and_restores_old_unique(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('monitor_page_hits', 'day'));
        $this->assertFalse(Schema::hasTable('monitor_settings'));

        $monitor = Monitor::create(['data' => []]);

        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'hits' => 1,
            'not_found' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        // sem `day`, o unique antigo (monitor_id, path) volta a valer.
        DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => 'example.com/a',
            'hits' => 1,
            'not_found' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
