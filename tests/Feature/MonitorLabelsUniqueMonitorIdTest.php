<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * v0.59.1: `monitor_labels.monitor_id` ganha o `unique` de verdade (ver
 * nota na migration `2026_10_04_000000_add_unique_monitor_id_to_monitor_labels_table`).
 * O teste de merge desfaz o índice com `down()`, cria duplicatas e roda
 * `up()` de novo — mesmo padrão de `BackfillHumanLabelMigrationTest`.
 */
class MonitorLabelsUniqueMonitorIdTest extends TestCase
{
    use RefreshDatabase;

    protected function migration(): object
    {
        return require dirname(__DIR__, 2).'/src/database/migrations/2026_10_04_000000_add_unique_monitor_id_to_monitor_labels_table.php';
    }

    public function test_second_label_for_the_same_monitor_is_rejected(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot']);

        $this->expectException(UniqueConstraintViolationException::class);

        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human']);
    }

    public function test_up_merges_existing_duplicates_before_creating_the_index(): void
    {
        $migration = $this->migration();
        $migration->down();

        // manual vence ai, mesmo mais antigo
        $a = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $a->id, 'kind' => 'bot', 'source' => 'manual', 'classified_at' => '2026-09-01 00:00:00']);
        MonitorLabel::create(['monitor_id' => $a->id, 'kind' => 'human', 'source' => 'ai', 'classified_at' => '2026-10-01 00:00:00']);

        // mesma origem: classified_at mais recente vence, null perde
        $b = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $b->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => null]);
        MonitorLabel::create(['monitor_id' => $b->id, 'kind' => 'human', 'source' => 'ai', 'classified_at' => '2026-10-02 00:00:00']);
        MonitorLabel::create(['monitor_id' => $b->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => '2026-09-02 00:00:00']);

        // sem duplicata: intocado
        $c = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $c->id, 'kind' => 'human', 'source' => 'auth']);

        $migration->up();

        $this->assertSame(3, MonitorLabel::count());
        $this->assertSame('manual', MonitorLabel::where('monitor_id', $a->id)->first()->source);
        $this->assertSame('human', MonitorLabel::where('monitor_id', $b->id)->first()->kind);
        $this->assertSame('auth', MonitorLabel::where('monitor_id', $c->id)->first()->source);

        $this->expectException(UniqueConstraintViolationException::class);
        MonitorLabel::create(['monitor_id' => $c->id, 'kind' => 'bot']);
    }
}
