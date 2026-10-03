<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 270 (v0.56.0): totais do cabeçalho do dashboard —
 * `sessions_total` removido, `monitors_by_kind` novo
 * (`{human, bot, unclassified, new}`, soma == `visitors_total`), com a
 * fronteira `new`/`unclassified` pelo mesmo `ai_triage_min_age_hours` da
 * fila de triagem IA (266).
 *
 * laravel-monitor 284 (v0.59.0): `monitors_by_kind` ganhou `human_guest`/
 * `human_user` (`human` = soma dos dois, mantido por compatibilidade) e
 * `flagged` (sem `kind`, visto em IP flagado/bloqueado) — `new`/
 * `unclassified` deixaram de contar `flagged` (por isso
 * `array_sum($byKind) - $byKind['human']` nas somas abaixo, não
 * `array_sum` cru: `human` é redundante com `human_guest`+`human_user`,
 * contaria em dobro).
 */
class MonitorGetDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `created_at` não é `$fillable` em `Monitor` (ver MonitorQueueTest),
     * daí o `forceFill()`.
     */
    protected function monitorAgedHours(float $hours): Monitor
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->forceFill(['created_at' => now()->subMinutes((int) round($hours * 60))])->save();

        return $monitor;
    }

    public function test_sessions_total_is_no_longer_in_the_response(): void
    {
        $response = $this->callHandler(['action' => 'getData']);

        $response->assertOk();
        $this->assertArrayNotHasKey('sessions_total', $response->json());
        $this->assertArrayHasKey('visits_total', $response->json());
    }

    public function test_monitors_by_kind_buckets_every_monitor_and_sums_to_visitors_total(): void
    {
        config(['monitor.ai_triage_min_age_hours' => 24]);

        // human: manual e IA (qualquer source conta).
        $h1 = $this->monitorAgedHours(48);
        MonitorLabel::create(['monitor_id' => $h1->id, 'kind' => 'human', 'source' => 'manual']);
        $h2 = $this->monitorAgedHours(1);
        MonitorLabel::create(['monitor_id' => $h2->id, 'kind' => 'human', 'source' => 'ai']);

        // bot.
        $b1 = $this->monitorAgedHours(72);
        MonitorLabel::create(['monitor_id' => $b1->id, 'kind' => 'bot', 'source' => 'ai']);

        // sem kind, velho: um sem label nenhum, um com label só de tags.
        $this->monitorAgedHours(30);
        $tagOnly = $this->monitorAgedHours(50);
        MonitorLabel::create(['monitor_id' => $tagOnly->id, 'tags' => ['vpn']]);

        // sem kind, novo (dentro da carência).
        $this->monitorAgedHours(2);
        Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'getData']);

        $response->assertOk();
        $byKind = $response->json('monitors_by_kind');

        $this->assertSame([
            'human' => 2,
            'human_guest' => 2,
            'human_user' => 0,
            'bot' => 1,
            'flagged' => 0,
            'unclassified' => 2,
            'new' => 2,
        ], $byKind);
        $this->assertSame(7, $response->json('visitors_total'));
        $this->assertSame(Monitor::count(), $response->json('visitors_total'));
        $this->assertSame($response->json('visitors_total'), array_sum($byKind) - $byKind['human']);
    }

    public function test_monitors_by_kind_splits_human_between_guest_and_user(): void
    {
        $guest = $this->monitorAgedHours(1);
        MonitorLabel::create(['monitor_id' => $guest->id, 'kind' => 'human']);

        $user = Monitor::create(['data' => ['user_id' => 99]]);
        $user->forceFill(['created_at' => now()->subHour()])->save();
        MonitorLabel::create(['monitor_id' => $user->id, 'kind' => 'human']);

        $byKind = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');

        $this->assertSame(1, $byKind['human_guest']);
        $this->assertSame(1, $byKind['human_user']);
        $this->assertSame(2, $byKind['human']);
    }

    public function test_monitors_by_kind_flagged_excludes_from_new_and_unclassified(): void
    {
        config(['monitor.ai_triage_min_age_hours' => 24]);

        $flaggedOld = $this->monitorAgedHours(30);
        IpStat::create([
            'ip' => '198.51.100.20', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true,
        ]);
        $flaggedOld->recordIp('198.51.100.20');

        $this->monitorAgedHours(30); // unclassified comum, não flagado

        $byKind = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');

        $this->assertSame(1, $byKind['flagged']);
        $this->assertSame(1, $byKind['unclassified'], 'o flagado não deveria contar em unclassified');
    }

    public function test_monitors_by_kind_kind_wins_over_flagged_ip(): void
    {
        $bot = $this->monitorAgedHours(1);
        MonitorLabel::create(['monitor_id' => $bot->id, 'kind' => 'bot']);
        IpStat::create([
            'ip' => '198.51.100.21', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true,
        ]);
        $bot->recordIp('198.51.100.21');

        $byKind = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');

        $this->assertSame(1, $byKind['bot']);
        $this->assertSame(0, $byKind['flagged']);
    }

    public function test_new_unclassified_boundary_respects_ai_triage_min_age_hours(): void
    {
        $this->monitorAgedHours(3);   // novo com 6h de carência, velho com 2h
        $this->monitorAgedHours(5);   // idem
        $this->monitorAgedHours(10);  // velho nos dois casos

        config(['monitor.ai_triage_min_age_hours' => 6]);
        $wide = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');
        $this->assertSame(2, $wide['new']);
        $this->assertSame(1, $wide['unclassified']);

        config(['monitor.ai_triage_min_age_hours' => 2]);
        Cache::flush();
        $narrow = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');
        $this->assertSame(0, $narrow['new']);
        $this->assertSame(3, $narrow['unclassified']);
    }

    public function test_monitors_by_kind_agrees_with_monitor_queue_counts_when_nothing_is_excluded(): void
    {
        config(['monitor.ai_triage_min_age_hours' => 24]);

        $this->monitorAgedHours(1);
        $this->monitorAgedHours(30);
        $this->monitorAgedHours(40);

        $byKind = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');
        $queue = $this->callHandler(['action' => 'getMonitorQueueCounts'])->json();

        $this->assertSame($queue['new'], $byKind['new']);
        $this->assertSame($queue['unclassified'], $byKind['unclassified']);
    }

    public function test_monitors_by_kind_is_a_single_query_without_group_by(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'monitor_labels')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $this->callHandler(['action' => 'getData'])->assertOk();

        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('group by', $queries[0]);
    }

    public function test_monitors_by_kind_is_cached_with_the_data_totals_ttl(): void
    {
        $this->monitorAgedHours(30);

        $first = $this->callHandler(['action' => 'getData'])->json('monitors_by_kind');
        $this->monitorAgedHours(30);
        $second = $this->callHandler(['action' => 'getData'])->json();

        $this->assertSame($first, $second['monitors_by_kind']);
        $this->assertSame(1, $second['visitors_total']);
    }

    public function test_monitors_by_kind_fails_open_without_monitor_labels_table(): void
    {
        config(['monitor.ai_triage_min_age_hours' => 24]);
        $this->monitorAgedHours(1);
        $this->monitorAgedHours(30);

        Schema::drop('monitor_labels');

        $response = $this->callHandler(['action' => 'getData']);

        $response->assertOk();
        $this->assertSame(
            ['human' => 0, 'human_guest' => 0, 'human_user' => 0, 'bot' => 0, 'flagged' => 0, 'unclassified' => 1, 'new' => 1],
            $response->json('monitors_by_kind')
        );
        $this->assertSame(2, $response->json('visitors_total'));
    }
}
