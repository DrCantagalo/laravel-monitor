<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorAccessLog;
use Drcantagalo\LaravelMonitor\Models\MonitorIpLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 242 (v0.50.0): novo read action `getTableStats` — uma
 * linha por tabela do pacote (rows/size_bytes) + um `total` agregado. Ver
 * `MonitorController::getTableStats()`/`buildTableStatsResult()` e
 * `STATS_TABLES`.
 */
class MonitorTableStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    public function test_returns_one_entry_per_package_table_with_correct_row_counts(): void
    {
        Monitor::create(['data' => []]);
        Monitor::create(['data' => []]);
        MonitorIpLabel::create(['ip' => '1.1.1.1', 'tags' => ['amazon']]);

        $response = $this->callHandler(['action' => 'getTableStats']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $rows = collect($response->json('data'))->keyBy('table');

        $this->assertSame(2, $rows['monitors']['rows']);
        $this->assertSame(1, $rows['monitor_ip_labels']['rows']);
        $this->assertSame(0, $rows['monitor_visits']['rows']);
        // getTableStats está em ACCESS_LOGGED_ACTIONS (desde a 242/v0.50.0)
        // — a própria chamada com local_token grava 1 linha
        // local_token_read em monitor_access_logs ANTES de rodar
        // buildTableStatsResult(), então a primeira chamada já vê 1, não 0
        // (ver test_monitor_access_logs_counts_toward_stats_and_total logo
        // abaixo pra um cenário com uma linha pré-existente).
        $this->assertSame(1, $rows['monitor_access_logs']['rows']);

        // Todas as 10 tabelas do pacote presentes (nenhuma
        // monitor_blocked_paths/monitor_path_reviews — fundidas em
        // monitor_paths). monitor_access_logs desde a laravel-monitor 257
        // (v0.52.0) — só entra aqui pra transparência de volume, nunca em
        // clearData/pruneData (ver MonitorAccessLogTest).
        $this->assertEqualsCanonicalizing([
            'monitors', 'monitor_visits', 'monitor_visit_ips', 'monitor_page_hits',
            'monitor_ip_stats', 'monitor_blocked_ips', 'monitor_paths',
            'monitor_block_results', 'monitor_ip_labels', 'monitor_access_logs',
        ], $rows->keys()->all());

        // 2 monitors + 1 monitor_ip_labels + 1 monitor_access_logs (a
        // própria chamada, ver acima) = 4.
        $this->assertSame(4, $response->json('total.rows'));
    }

    public function test_monitor_access_logs_counts_toward_stats_and_total(): void
    {
        MonitorAccessLog::create([
            'accessed_at' => now(),
            'kind' => 'local_token_read',
            'action' => 'getData',
            'ip' => '203.0.113.5',
        ]);

        $response = $this->callHandler(['action' => 'getTableStats']);

        $response->assertOk();

        $rows = collect($response->json('data'))->keyBy('table');

        // 1 linha pré-existente + 1 gerada pela própria chamada de
        // getTableStats (ACCESS_LOGGED_ACTIONS, ver comentário no teste
        // acima) = 2.
        $this->assertSame(2, $rows['monitor_access_logs']['rows']);
        $this->assertArrayHasKey('size_bytes', $rows['monitor_access_logs']);
        $this->assertSame(2, $response->json('total.rows'));
    }

    public function test_size_bytes_is_null_on_sqlite_but_rows_are_still_reported(): void
    {
        Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'getTableStats']);

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('table', 'monitors');
        $this->assertArrayHasKey('size_bytes', $row);
        $this->assertNull($row['size_bytes']);
        $this->assertSame(1, $row['rows']);

        $this->assertArrayHasKey('size_bytes', $response->json('total'));
        $this->assertNull($response->json('total.size_bytes'));
    }

    public function test_a_package_table_that_has_not_migrated_yet_is_skipped_gracefully(): void
    {
        Schema::drop('monitor_ip_labels');

        $response = $this->callHandler(['action' => 'getTableStats']);

        $response->assertOk();

        $tables = collect($response->json('data'))->pluck('table')->all();
        $this->assertNotContains('monitor_ip_labels', $tables);
        $this->assertCount(9, $tables);
    }

    public function test_a_missing_monitor_access_logs_table_is_skipped_gracefully(): void
    {
        Schema::drop('monitor_access_logs');

        $response = $this->callHandler(['action' => 'getTableStats']);

        $response->assertOk();

        $tables = collect($response->json('data'))->pluck('table')->all();
        $this->assertNotContains('monitor_access_logs', $tables);
        $this->assertCount(9, $tables);
    }

    public function test_result_is_cached_and_a_direct_db_write_is_not_reflected_until_invalidated(): void
    {
        Monitor::create(['data' => []]);

        $first = $this->callHandler(['action' => 'getTableStats']);
        $first->assertOk();
        $this->assertSame(1, collect($first->json('data'))->firstWhere('table', 'monitors')['rows']);

        // Escreve direto no banco, contornando qualquer invalidação de
        // cache da aplicação.
        DB::table('monitors')->insert(['data' => null, 'created_at' => now(), 'updated_at' => now()]);

        $second = $this->callHandler(['action' => 'getTableStats']);
        $second->assertOk();
        $this->assertSame(
            1,
            collect($second->json('data'))->firstWhere('table', 'monitors')['rows'],
            'segunda chamada deveria vir do cache, ainda vendo 1 linha'
        );

        // clearData invalida o cache de listagens (mesmo cache versionado
        // usado por getTableStats) E apaga todos os Monitor.
        $this->callHandler(['action' => 'clearData']);

        $third = $this->callHandler(['action' => 'getTableStats']);
        $third->assertOk();
        $this->assertSame(
            0,
            collect($third->json('data'))->firstWhere('table', 'monitors')['rows'],
            'depois de clearData o cache deveria estar invalidado, refletindo o estado real (vazio)'
        );
    }

    public function test_cache_is_invalidated_after_prune_data(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->timestamps = false;
        $monitor->updated_at = now()->subDays(30);
        $monitor->save();

        $first = $this->callHandler(['action' => 'getTableStats']);
        $this->assertSame(1, collect($first->json('data'))->firstWhere('table', 'monitors')['rows']);

        $prune = $this->callHandler(['action' => 'pruneData', 'older_than_days' => 1]);
        $prune->assertOk();
        $prune->assertJsonPath('monitors_deleted', 1);

        $second = $this->callHandler(['action' => 'getTableStats']);
        $this->assertSame(0, collect($second->json('data'))->firstWhere('table', 'monitors')['rows']);
    }

    public function test_accepts_ephemeral_read_token(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'getTableStats'], $token);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'getTableStats']);

        $response->assertStatus(401);
    }
}
