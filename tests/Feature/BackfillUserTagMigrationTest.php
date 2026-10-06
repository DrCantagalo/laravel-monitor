<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 295 (v0.61.0): backfill da tag reservada `user` pra
 * Monitors que já tinham `data['user_id']` gravado ANTES desta versão
 * existir (ver nota na própria migration,
 * `2026_10_06_000000_backfill_user_tag_for_authenticated_monitors`).
 *
 * Mesmo padrão de `BackfillHumanLabelMigrationTest`: `RefreshDatabase` já
 * roda esta migration (vazia, nenhum Monitor existe ainda) a cada teste —
 * os testes abaixo chamam `up()` de novo direto, pra popular dados DEPOIS
 * da migration normal e então exercitar a lógica isoladamente.
 */
class BackfillUserTagMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function runMigrationUp(): void
    {
        (require dirname(__DIR__, 2).'/src/database/migrations/2026_10_06_000000_backfill_user_tag_for_authenticated_monitors.php')->up();
    }

    public function test_adds_the_user_tag_to_a_monitor_with_user_id_and_no_label_row(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 10]]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame(['user'], $label->tags);
    }

    public function test_adds_the_user_tag_to_an_existing_label_row_preserving_other_tags_and_kind(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 11]]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual', 'tags' => ['vpn']]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('bot', $label->kind, 'o backfill de tag não deve tocar em kind/source');
        $this->assertSame('manual', $label->source);
        $this->assertEqualsCanonicalizing(['vpn', 'user'], $label->tags);
    }

    public function test_ignores_a_label_that_already_has_the_user_tag(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 12]]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => ['user']]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame(['user'], $label->tags);
    }

    public function test_ignores_monitors_without_user_id(): void
    {
        Monitor::create(['data' => []]);

        $this->runMigrationUp();

        $this->assertSame(0, MonitorLabel::count());
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 14]]);

        $this->runMigrationUp();
        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame(['user'], $label->tags);
        $this->assertSame(1, MonitorLabel::where('monitor_id', $monitor->id)->count());
    }

    public function test_handles_many_monitors_across_the_chunk_boundary(): void
    {
        $monitorIds = collect(range(1, 520))->map(function ($i) {
            return Monitor::create(['data' => ['user_id' => $i]])->id;
        });

        $this->runMigrationUp();

        $this->assertSame(520, MonitorLabel::query()->whereJsonContains('tags', 'user')->count());
        $this->assertSame(520, $monitorIds->count());
    }
}
