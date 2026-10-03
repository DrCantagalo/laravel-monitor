<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 284 (v0.59.0): backfill pro auto-human de Monitors que já
 * tinham `data['user_id']` gravado ANTES desta versão existir (ver nota na
 * própria migration,
 * `2026_10_03_000000_backfill_human_label_for_authenticated_monitors`).
 *
 * `RefreshDatabase` já roda esta migration (vazia, nenhum Monitor existe
 * ainda) a cada teste — os testes abaixo chamam `up()` de novo direto,
 * mesmo padrão de `MonitorLabelsMigrationTest`, pra popular dados DEPOIS
 * da migration normal e then exercitar a lógica isoladamente.
 */
class BackfillHumanLabelMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function runMigrationUp(): void
    {
        (require dirname(__DIR__, 2).'/src/database/migrations/2026_10_03_000000_backfill_human_label_for_authenticated_monitors.php')->up();
    }

    public function test_classifies_a_monitor_with_user_id_and_no_label_row(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 10]]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
        $this->assertNotNull($label->classified_at);
    }

    public function test_fills_kind_on_an_existing_label_row_without_kind_preserving_tags(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 11]]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => ['vpn']]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
        $this->assertSame(['vpn'], $label->tags);
    }

    public function test_never_overwrites_an_already_classified_monitor(): void
    {
        $bot = Monitor::create(['data' => ['user_id' => 12]]);
        MonitorLabel::create(['monitor_id' => $bot->id, 'kind' => 'bot', 'source' => 'manual']);

        $human = Monitor::create(['data' => ['user_id' => 13]]);
        MonitorLabel::create(['monitor_id' => $human->id, 'kind' => 'human', 'source' => 'ai']);

        $this->runMigrationUp();

        $this->assertSame('bot', MonitorLabel::where('monitor_id', $bot->id)->first()->kind);
        $this->assertSame('manual', MonitorLabel::where('monitor_id', $bot->id)->first()->source);
        $this->assertSame('ai', MonitorLabel::where('monitor_id', $human->id)->first()->source, 'source não deveria virar auth num Monitor já classificado pela IA');
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
        $firstClassifiedAt = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        $this->runMigrationUp();

        $this->assertSame(1, MonitorLabel::where('monitor_id', $monitor->id)->count());
        $this->assertEquals($firstClassifiedAt, MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at);
    }

    public function test_handles_many_monitors_across_the_chunk_boundary(): void
    {
        $monitorIds = collect(range(1, 520))->map(function ($i) {
            return Monitor::create(['data' => ['user_id' => $i]])->id;
        });

        $this->runMigrationUp();

        $this->assertSame(520, MonitorLabel::where('kind', 'human')->where('source', 'auth')->count());
        $this->assertSame(520, $monitorIds->count());
    }
}
