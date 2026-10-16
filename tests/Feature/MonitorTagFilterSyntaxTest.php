<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 369 (v0.72.0): sintaxe nova do filtro `tag` —
 * `*` (tem alguma tag CUSTOM), `-` (não tem nenhuma) e `-foo` (não tem a
 * tag `foo`) — além do comportamento de sempre (tag exata). A tag
 * reservada `user` (`MonitorLabel::TAG_USER`, automática) NUNCA conta
 * pra `*`/`-`, só pra `-user`/`user` (nome exato). Cobre as duas listas
 * que usam o mesmo filtro: `getVisitorsByIp` (agregado por IP, inclusive
 * `filter=blocked`) e `getMonitorQueue` (direto no Monitor). A rejeição
 * dessas sintaxes como tag de verdade (`setMonitorTags`) é coberta em
 * `MonitorSetMonitorLabelTest`.
 */
class MonitorTagFilterSyntaxTest extends TestCase
{
    use RefreshDatabase;

    protected function ipStat(string $ip): void
    {
        IpStat::create([
            'ip' => $ip,
            'visit_count' => 1,
            'first_seen' => now(),
            'last_seen' => now(),
            'flagged' => false,
        ]);
    }

    protected function monitorAtIp(string $ip, ?array $tags = null): Monitor
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp($ip);

        if ($tags !== null) {
            MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => $tags]);
        }

        return $monitor;
    }

    // --- getVisitorsByIp (agregado por IP) -------------------------------

    public function test_any_matches_ip_with_a_custom_tag_and_excludes_ip_without_one(): void
    {
        $this->ipStat('1.1.1.1');
        $this->monitorAtIp('1.1.1.1', ['amazon']);

        $this->ipStat('2.2.2.2');
        $this->monitorAtIp('2.2.2.2', []);

        $this->ipStat('3.3.3.3');
        $this->monitorAtIp('3.3.3.3', ['user']);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'tag' => '*']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertSame(['1.1.1.1'], $ips);
    }

    public function test_none_matches_ip_without_any_custom_tag(): void
    {
        $this->ipStat('1.1.1.1');
        $this->monitorAtIp('1.1.1.1', ['amazon']);

        $this->ipStat('2.2.2.2');
        $this->monitorAtIp('2.2.2.2', []);

        $this->ipStat('3.3.3.3');
        $this->monitorAtIp('3.3.3.3', ['user']);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'tag' => '-', 'include_without_monitors' => true]);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('1.1.1.1', $ips);
        $this->assertContains('2.2.2.2', $ips);
        $this->assertContains('3.3.3.3', $ips);
    }

    public function test_exclude_syntax_matches_ip_whose_monitors_never_have_that_tag(): void
    {
        $this->ipStat('1.1.1.1');
        $this->monitorAtIp('1.1.1.1', ['foo']);

        $this->ipStat('2.2.2.2');
        $this->monitorAtIp('2.2.2.2', ['bar']);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'tag' => '-foo']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('1.1.1.1', $ips);
        $this->assertContains('2.2.2.2', $ips);
    }

    public function test_exclude_user_excludes_ip_with_an_authenticated_monitor(): void
    {
        $this->ipStat('1.1.1.1');
        $this->monitorAtIp('1.1.1.1', ['user']);

        $this->ipStat('2.2.2.2');
        $this->monitorAtIp('2.2.2.2', []);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'tag' => '-user', 'include_without_monitors' => true]);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('1.1.1.1', $ips);
        $this->assertContains('2.2.2.2', $ips);
    }

    public function test_exact_user_still_filters_by_name_unaffected_by_the_any_none_reservation(): void
    {
        $this->ipStat('1.1.1.1');
        $this->monitorAtIp('1.1.1.1', ['user']);

        $this->ipStat('2.2.2.2');
        $this->monitorAtIp('2.2.2.2', []);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'tag' => 'user']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertSame(['1.1.1.1'], $ips);
    }

    // --- getVisitorsByIp?filter=blocked (buildBlockedVisitorsResult) -----

    public function test_blocked_tab_any_and_none_follow_the_same_semantics(): void
    {
        BlockedIp::create(['ip' => '1.1.1.1', 'strike_count' => 1, 'lifetime_offense_count' => 1, 'last_offense_at' => now()]);
        $this->monitorAtIp('1.1.1.1', ['amazon']);

        BlockedIp::create(['ip' => '2.2.2.2', 'strike_count' => 1, 'lifetime_offense_count' => 1, 'last_offense_at' => now()]);
        $this->monitorAtIp('2.2.2.2', ['user']);

        $any = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'blocked', 'tag' => '*']);
        $this->assertSame(['1.1.1.1'], collect($any->json('data'))->pluck('ip')->all());

        $none = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'blocked', 'tag' => '-']);
        $this->assertSame(['2.2.2.2'], collect($none->json('data'))->pluck('ip')->all());
    }

    // --- getMonitorQueue (direto no Monitor) ------------------------------

    protected function labeledMonitor(?array $tags): Monitor
    {
        $monitor = Monitor::create(['data' => []]);

        if ($tags !== null) {
            MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => $tags]);
        }

        return $monitor;
    }

    public function test_monitor_queue_any_matches_monitor_with_a_custom_tag(): void
    {
        $withCustomTag = $this->labeledMonitor(['amazon']);
        $withoutTags = $this->labeledMonitor([]);
        $userOnly = $this->labeledMonitor(['user']);
        $noLabelAtAll = $this->labeledMonitor(null);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all', 'tag' => '*']);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$withCustomTag->id], $ids);
        $this->assertNotContains($withoutTags->id, $ids);
        $this->assertNotContains($userOnly->id, $ids);
        $this->assertNotContains($noLabelAtAll->id, $ids);
    }

    public function test_monitor_queue_none_matches_monitor_without_any_custom_tag(): void
    {
        $withCustomTag = $this->labeledMonitor(['amazon']);
        $withoutTags = $this->labeledMonitor([]);
        $userOnly = $this->labeledMonitor(['user']);
        $noLabelAtAll = $this->labeledMonitor(null);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all', 'tag' => '-']);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($withCustomTag->id, $ids);
        $this->assertContains($withoutTags->id, $ids);
        $this->assertContains($userOnly->id, $ids);
        $this->assertContains($noLabelAtAll->id, $ids);
    }

    public function test_monitor_queue_exclude_syntax_matches_monitor_never_tagged_foo(): void
    {
        $taggedFoo = $this->labeledMonitor(['foo']);
        $taggedBar = $this->labeledMonitor(['bar']);
        $noLabelAtAll = $this->labeledMonitor(null);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all', 'tag' => '-foo']);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($taggedFoo->id, $ids);
        $this->assertContains($taggedBar->id, $ids);
        $this->assertContains($noLabelAtAll->id, $ids);
    }

    public function test_monitor_queue_exclude_user_excludes_only_the_authenticated_monitor(): void
    {
        $authenticated = $this->labeledMonitor(['user']);
        $guest = $this->labeledMonitor(['amazon']);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all', 'tag' => '-user']);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($authenticated->id, $ids);
        $this->assertContains($guest->id, $ids);
    }
}
