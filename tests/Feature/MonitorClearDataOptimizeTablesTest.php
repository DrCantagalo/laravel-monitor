<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 445 (origem: pedido do usuário 2026-11-05, "getTableStats
 * devolve tamanho velho depois de apagar dados"). `OPTIMIZE TABLE` é o
 * jeito real de recuperar espaço no InnoDB (DELETE só marca páginas como
 * livres pra reuso, nunca encolhe o arquivo) mas reconstrói a tabela
 * inteira — por isso é opt-in (`optimize_tables=true` em `clearData`,
 * nunca automático) e só nas tabelas que perderam linha nesta chamada.
 *
 * O driver de teste deste pacote é sempre SQLite (ver guias/sandbox.md do
 * claude-manager e o comentário em `MonitorTableStatsTest::
 * test_size_bytes_is_null_on_sqlite_but_rows_are_still_reported`) — o
 * `OPTIMIZE TABLE`/`SET SESSION information_schema_stats_expiry` em si só
 * roda em MySQL (`DB::connection()->getDriverName() === 'mysql'`), então
 * aqui só é possível confirmar que o parâmetro é aceito e não quebra nada
 * no SQLite (nem com o flag true nem omitido) — a validação de que a
 * query certa roda em MySQL de verdade é a do harness/produção.
 */
class MonitorClearDataOptimizeTablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    public function test_optimize_tables_true_is_accepted_and_does_not_change_the_response_shape(): void
    {
        Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'clearData', 'optimize_tables' => true]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('monitors_deleted', 1);
    }

    public function test_optimize_tables_omitted_defaults_to_false_same_as_before(): void
    {
        Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'clearData']);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
    }

    public function test_optimize_tables_true_with_nothing_deleted_is_a_safe_no_op(): void
    {
        $response = $this->callHandler(['action' => 'clearData', 'optimize_tables' => true]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 0);
    }
}
