<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 258 (v0.53.0): novas read actions `getMonitorQueue`
 * (`group=unclassified`|`group=recheck`) e `getMonitorQueueCounts` —
 * substituem o filtro `clean_ai_queue` de `getVisitorsByIp` (removido
 * nesta mesma versão), agora listando Monitors em vez de IPs. Ver README
 * "IP classification".
 */
class MonitorQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_unclassified_group_lists_monitors_without_any_kind(): void
    {
        $unclassified = Monitor::create(['data' => []]);

        $classified = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $classified->id, 'kind' => 'bot', 'source' => 'manual']);

        $tagOnly = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $tagOnly->id, 'tags' => ['vpn']]);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unclassified->id, $ids);
        $this->assertContains($tagOnly->id, $ids);
        $this->assertNotContains($classified->id, $ids);
    }

    public function test_unclassified_group_excludes_monitors_seen_from_a_flagged_ip(): void
    {
        IpStat::create(['ip' => '1.1.1.1', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $this->assertNotContains($monitor->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_unclassified_group_excludes_monitors_seen_from_a_blocked_ip(): void
    {
        BlockedIp::create(['ip' => '1.1.1.1']);

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $this->assertNotContains($monitor->id, collect($response->json('data'))->pluck('id')->all());
    }

    protected function pageHit(Monitor $monitor, string $path, int $hits, $createdAt): void
    {
        \Illuminate\Support\Facades\DB::table('monitor_page_hits')->insert([
            'monitor_id' => $monitor->id,
            'path' => $path,
            'hits' => $hits,
            'not_found' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_recheck_group_requires_ai_source_and_enough_new_hits(): void
    {
        config(['monitor.ai_recheck_min_new_hits' => 10]);

        $eligible = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $eligible->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()->subDay()]);
        $this->pageHit($eligible, 'new-path', 15, now());

        $notEnoughHits = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $notEnoughHits->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()->subDay()]);
        $this->pageHit($notEnoughHits, 'new-path', 3, now());

        $manualMonitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $manualMonitor->id, 'kind' => 'bot', 'source' => 'manual', 'classified_at' => now()->subDay()]);
        $this->pageHit($manualMonitor, 'new-path', 50, now());

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'recheck']);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$eligible->id], $ids);
    }

    public function test_recheck_ignores_hits_created_before_classified_at(): void
    {
        config(['monitor.ai_recheck_min_new_hits' => 10]);

        $monitor = Monitor::create(['data' => []]);
        $classifiedAt = now();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => $classifiedAt]);

        // Hits antigos, de antes da classificação — não contam pro limiar.
        $this->pageHit($monitor, 'old-path', 100, $classifiedAt->copy()->subDay());

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'recheck']);

        $this->assertSame([], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_recheck_summary_splits_hits_before_and_after_classification(): void
    {
        config(['monitor.ai_recheck_min_new_hits' => 5]);

        $monitor = Monitor::create(['data' => []]);
        $classifiedAt = now();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => $classifiedAt]);

        $this->pageHit($monitor, 'old-path', 4, $classifiedAt->copy()->subDay());
        $this->pageHit($monitor, 'new-path-1', 3, $classifiedAt->copy()->addHour());
        $this->pageHit($monitor, 'new-path-2', 4, $classifiedAt->copy()->addHours(2));

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'recheck']);

        $row = collect($response->json('data'))->firstWhere('id', $monitor->id);
        $this->assertNotNull($row);
        $this->assertSame(4, $row['recheck_summary']['before']['hits']);
        $this->assertSame(1, $row['recheck_summary']['before']['distinct_paths']);
        $this->assertSame(7, $row['recheck_summary']['after']['hits']);
        $this->assertSame(2, $row['recheck_summary']['after']['distinct_paths']);
    }

    public function test_invalid_group_returns_422(): void
    {
        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'bogus']);

        $response->assertStatus(422);
    }

    public function test_counts_action_returns_both_numbers(): void
    {
        Monitor::create(['data' => []]);

        config(['monitor.ai_recheck_min_new_hits' => 5]);
        $recheckMonitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $recheckMonitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()->subDay()]);
        $this->pageHit($recheckMonitor, 'new-path', 10, now());

        $response = $this->callHandler(['action' => 'getMonitorQueueCounts']);

        $response->assertOk();
        $response->assertJsonPath('unclassified', 1);
        $response->assertJsonPath('recheck', 1);
    }

    /**
     * laravel-monitor 260 (v0.53.1, hotfix): a query do grupo `recheck`
     * usava `->get()->count()`, que seleciona `*` (todas as colunas de
     * `monitor_labels`) numa query com `groupBy('ml.monitor_id')` — MySQL
     * com `sql_mode=only_full_group_by` recusa (erro 1055), já que nem
     * toda coluna selecionada está no GROUP BY nem é agregada. SQLite (o
     * driver dos testes) não aplica essa regra, por isso o bug passou
     * despercebido; este teste inspeciona o SQL gerado em vez de confiar
     * no comportamento do driver, pra pegar a regressão em qualquer banco.
     */
    public function test_counts_action_recheck_query_only_selects_grouped_column(): void
    {
        config(['monitor.ai_recheck_min_new_hits' => 5]);
        $recheckMonitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $recheckMonitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()->subDay()]);
        $this->pageHit($recheckMonitor, 'new-path', 10, now());

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->callHandler(['action' => 'getMonitorQueueCounts']);

        $response->assertOk();
        $response->assertJsonPath('recheck', 1);

        $groupByQueries = array_filter($queries, fn ($sql) => str_contains($sql, 'group by') && str_contains($sql, 'monitor_labels'));
        $this->assertNotEmpty($groupByQueries, 'Expected a grouped query against monitor_labels to run.');

        foreach ($groupByQueries as $sql) {
            $this->assertStringNotContainsString('select *', $sql, 'GROUP BY query must not select *, only the grouped column — breaks under MySQL only_full_group_by.');
        }
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $response->assertStatus(401);
    }

    public function test_accepts_ephemeral_read_token(): void
    {
        config(['monitor.local_token' => 'test-local-token']);
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified'], $token);

        $response->assertOk();
    }
}
