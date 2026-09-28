<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorIpLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 257 (v0.52.0): novo write action `spreadIpLabel` —
 * propaga `kind`/tags de um IP de origem já classificado pros seus
 * "vizinhos" (outros IPs vistos nos mesmos `Monitor`, via
 * `monitor_visit_ips`), 1 hop só, nunca recursivo. Ver
 * `MonitorController::spreadIpLabel()`.
 */
class MonitorSpreadIpLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    /**
     * Liga um IP a um Monitor via monitor_visit_ips — mesma tabela lida
     * por getIpMonitors/getVisitorPaths, mantida em produção por
     * Monitor::recordIp().
     */
    protected function linkIpToMonitor(Monitor $monitor, string $ip): void
    {
        $monitor->recordIp($ip);
    }

    public function test_spreads_kind_and_tags_to_one_hop_neighbors_only(): void
    {
        // origin (1.1.1.1) e vizinho (2.2.2.2) compartilham o Monitor A.
        $monitorA = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitorA, '1.1.1.1');
        $this->linkIpToMonitor($monitorA, '2.2.2.2');

        // vizinho-do-vizinho: 2.2.2.2 também aparece no Monitor B, junto
        // com 3.3.3.3 — que NUNCA compartilhou nenhum Monitor com a
        // origem diretamente. Não deve ser tocado (1 hop só).
        $monitorB = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitorB, '2.2.2.2');
        $this->linkIpToMonitor($monitorB, '3.3.3.3');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual', 'tags' => ['scanner']]);

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('dry_run', false);
        $this->assertSame(['2.2.2.2'], $response->json('applied'));

        $neighbor = MonitorIpLabel::where('ip', '2.2.2.2')->first();
        $this->assertNotNull($neighbor);
        $this->assertSame('bot', $neighbor->kind);
        $this->assertSame('spread', $neighbor->source);
        $this->assertSame(['scanner'], $neighbor->tags);
        $this->assertNotNull($neighbor->classified_at);
        $this->assertStringContainsString('spread from 1.1.1.1', (string) $neighbor->note);

        // 3.3.3.3 nunca compartilhou um Monitor com a origem diretamente
        // — não deve existir nenhuma linha criada pra ele.
        $this->assertNull(MonitorIpLabel::where('ip', '3.3.3.3')->first());
    }

    public function test_tags_are_merged_not_replaced_and_deduped(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual', 'tags' => ['scanner', 'amazon']]);
        MonitorIpLabel::create(['ip' => '2.2.2.2', 'kind' => 'bot', 'source' => 'ai', 'tags' => ['amazon', 'datacenter']]);

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertOk();

        $neighbor = MonitorIpLabel::where('ip', '2.2.2.2')->first();
        $this->assertEqualsCanonicalizing(['amazon', 'datacenter', 'scanner'], $neighbor->tags);
        $this->assertSame('spread', $neighbor->source);
    }

    public function test_manual_labeled_neighbor_is_fully_untouched(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual', 'tags' => ['scanner']]);
        MonitorIpLabel::create(['ip' => '2.2.2.2', 'kind' => 'human', 'source' => 'manual', 'tags' => ['vip'], 'note' => 'known customer']);

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertOk();
        $this->assertSame([], $response->json('applied'));
        $this->assertSame([['ip' => '2.2.2.2', 'reason' => 'manual']], $response->json('skipped'));

        $neighbor = MonitorIpLabel::where('ip', '2.2.2.2')->first();
        $this->assertSame('human', $neighbor->kind);
        $this->assertSame('manual', $neighbor->source);
        $this->assertSame(['vip'], $neighbor->tags);
        $this->assertSame('known customer', $neighbor->note);
    }

    public function test_blocked_neighbor_is_skipped(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual']);
        BlockedIp::create(['ip' => '2.2.2.2']);

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertOk();
        $this->assertSame([], $response->json('applied'));
        $this->assertSame([['ip' => '2.2.2.2', 'reason' => 'blocked']], $response->json('skipped'));
        $this->assertNull(MonitorIpLabel::where('ip', '2.2.2.2')->first());
    }

    public function test_max_targets_limit_rejects_whole_batch_without_applying_anything(): void
    {
        config(['monitor.ip_spread_max_targets' => 2]);

        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');
        $this->linkIpToMonitor($monitor, '3.3.3.3');
        $this->linkIpToMonitor($monitor, '4.4.4.4');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual']);

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('neighbors_found', 3);

        $this->assertNull(MonitorIpLabel::where('ip', '2.2.2.2')->first());
        $this->assertNull(MonitorIpLabel::where('ip', '3.3.3.3')->first());
        $this->assertNull(MonitorIpLabel::where('ip', '4.4.4.4')->first());
    }

    public function test_dry_run_reports_targets_and_skipped_without_writing_anything(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');
        $this->linkIpToMonitor($monitor, '3.3.3.3');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual', 'tags' => ['scanner']]);
        MonitorIpLabel::create(['ip' => '3.3.3.3', 'kind' => 'human', 'source' => 'manual']);

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1', 'dry_run' => true]);

        $response->assertOk();
        $response->assertJsonPath('dry_run', true);

        $targets = collect($response->json('targets'))->pluck('ip')->all();
        $this->assertSame(['2.2.2.2'], $targets);

        $skipped = collect($response->json('skipped'))->pluck('ip')->all();
        $this->assertSame(['3.3.3.3'], $skipped);

        // Nada gravado.
        $this->assertNull(MonitorIpLabel::where('ip', '2.2.2.2')->first());
        $unchanged = MonitorIpLabel::where('ip', '3.3.3.3')->first();
        $this->assertSame('manual', $unchanged->source);
    }

    public function test_origin_without_kind_returns_422(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_invalid_ip_returns_422(): void
    {
        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => 'not-an-ip']);

        $response->assertStatus(422);
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);

        $response->assertStatus(401);
    }

    public function test_ephemeral_read_token_cannot_call_spread_ip_label(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1'], $token);

        $response->assertStatus(401);
    }

    public function test_a_later_ai_classification_does_not_overwrite_a_spread_sourced_label(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $this->linkIpToMonitor($monitor, '1.1.1.1');
        $this->linkIpToMonitor($monitor, '2.2.2.2');

        MonitorIpLabel::create(['ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'manual']);

        $spread = $this->callHandler(['action' => 'spreadIpLabel', 'ip' => '1.1.1.1']);
        $spread->assertOk();

        $neighbor = MonitorIpLabel::where('ip', '2.2.2.2')->first();
        $this->assertSame('bot', $neighbor->kind);
        $this->assertSame('spread', $neighbor->source);

        // Uma triagem IA tentando reclassificar como human não pode
        // vencer um kind com source=spread — mesma proteção de source=manual.
        $ai = $this->callHandler(['action' => 'setIpKind', 'ip' => '2.2.2.2', 'kind' => 'human', 'source' => 'ai']);

        $ai->assertOk();
        $this->assertSame([], $ai->json('applied'));
        $this->assertSame([['ip' => '2.2.2.2', 'reason' => 'spread classification protected']], $ai->json('ignored'));

        $neighbor->refresh();
        $this->assertSame('bot', $neighbor->kind);
        $this->assertSame('spread', $neighbor->source);
    }

    public function test_spread_sourced_ip_is_excluded_from_clean_ai_queue_like_manual(): void
    {
        Monitor::create(['data' => []]);

        IpStat::create([
            'ip' => '2.2.2.2',
            'visit_count' => 1,
            'first_seen' => now(),
            'last_seen' => now(),
            'flagged' => false,
        ]);

        MonitorIpLabel::create([
            'ip' => '2.2.2.2',
            'kind' => 'bot',
            'source' => 'spread',
            'classified_at' => now()->subDay(),
        ]);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'clean_ai_queue']);

        $response->assertOk();
        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('2.2.2.2', $ips);
    }
}
