<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 258 (v0.53.0): a migration
 * `2026_09_30_000000_move_ip_labels_to_monitor_labels_table` já rodou uma
 * vez (vazia) durante o `RefreshDatabase` normal — estes testes recriam
 * `monitor_ip_labels` manualmente, populam com dados de teste, e chamam
 * `up()` de novo direto (via `require` do arquivo) pra exercitar a lógica
 * de merge/conflito isoladamente, sem precisar de um dump de produção.
 */
class MonitorLabelsMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function recreateLegacyIpLabelsTable(): void
    {
        Schema::dropIfExists('monitor_labels');
        Schema::dropIfExists('monitor_ip_labels');

        Schema::create('monitor_ip_labels', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            $table->string('kind')->nullable();
            $table->json('tags')->nullable();
            $table->text('note')->nullable();
            $table->string('source')->default('manual');
            $table->dateTime('classified_at')->nullable();
            $table->timestamps();
        });
    }

    protected function insertLegacyLabel(array $attributes): void
    {
        DB::table('monitor_ip_labels')->insert(array_merge([
            'kind' => null,
            'tags' => null,
            'note' => null,
            'source' => 'manual',
            'classified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    protected function runMigrationUp(): void
    {
        (require dirname(__DIR__, 2).'/src/database/migrations/2026_09_30_000000_move_ip_labels_to_monitor_labels_table.php')->up();
    }

    public function test_copies_a_simple_label_to_the_monitors_seen_from_that_ip(): void
    {
        $this->recreateLegacyIpLabelsTable();

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');

        $this->insertLegacyLabel([
            'ip' => '1.1.1.1',
            'kind' => 'bot',
            'tags' => json_encode(['scanner']),
            'source' => 'ai',
            'classified_at' => now()->subDay(),
        ]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame('bot', $label->kind);
        $this->assertSame(['scanner'], $label->tags);
        $this->assertSame('ai', $label->source);
        $this->assertFalse(Schema::hasTable('monitor_ip_labels'));
    }

    public function test_manual_always_wins_over_any_other_source_regardless_of_recency(): void
    {
        $this->recreateLegacyIpLabelsTable();

        // O mesmo Monitor foi visto nos dois IPs — um rotulado manual (mas
        // mais antigo), outro rotulado por IA (mais recente). Manual
        // vence mesmo sendo o candidato mais antigo.
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');
        $monitor->recordIp('2.2.2.2');

        $this->insertLegacyLabel([
            'ip' => '1.1.1.1',
            'kind' => 'human',
            'source' => 'manual',
            'classified_at' => now()->subWeek(),
        ]);

        $this->insertLegacyLabel([
            'ip' => '2.2.2.2',
            'kind' => 'bot',
            'source' => 'ai',
            'classified_at' => now(),
        ]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('manual', $label->source);
    }

    public function test_most_recent_classified_at_wins_when_neither_candidate_is_manual(): void
    {
        $this->recreateLegacyIpLabelsTable();

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');
        $monitor->recordIp('2.2.2.2');

        $this->insertLegacyLabel([
            'ip' => '1.1.1.1',
            'kind' => 'bot',
            'source' => 'ai',
            'classified_at' => now()->subDay(),
        ]);

        $this->insertLegacyLabel([
            'ip' => '2.2.2.2',
            'kind' => 'human',
            'source' => 'ai',
            'classified_at' => now(),
        ]);

        $this->runMigrationUp();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
    }

    public function test_a_label_whose_ip_has_no_monitor_at_all_is_discarded(): void
    {
        $this->recreateLegacyIpLabelsTable();

        $this->insertLegacyLabel([
            'ip' => '9.9.9.9',
            'kind' => 'bot',
            'source' => 'manual',
            'classified_at' => now(),
        ]);

        $this->runMigrationUp();

        $this->assertSame(0, MonitorLabel::count());
    }

    public function test_a_monitor_seen_from_two_ips_with_no_conflicting_label_still_only_gets_one_row(): void
    {
        $this->recreateLegacyIpLabelsTable();

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');
        $monitor->recordIp('2.2.2.2');

        $this->insertLegacyLabel([
            'ip' => '1.1.1.1',
            'kind' => 'bot',
            'source' => 'manual',
            'classified_at' => now(),
        ]);

        $this->runMigrationUp();

        $this->assertSame(1, MonitorLabel::where('monitor_id', $monitor->id)->count());
    }

    public function test_down_recreates_monitor_ip_labels_empty_without_restoring_data(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot']);

        (require dirname(__DIR__, 2).'/src/database/migrations/2026_09_30_000000_move_ip_labels_to_monitor_labels_table.php')->down();

        $this->assertTrue(Schema::hasTable('monitor_ip_labels'));
        $this->assertFalse(Schema::hasTable('monitor_labels'));
        $this->assertSame(0, DB::table('monitor_ip_labels')->count());
    }
}
