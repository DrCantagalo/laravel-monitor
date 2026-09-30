<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 258 (v0.53.0): `getVisitorsByIp` deixou de ler
 * `monitor_ip_labels` e passou a DERIVAR kind/tags a partir dos Monitors
 * vistos em cada IP (`monitor_visit_ips` + `monitor_labels`) — ver README
 * "IP classification".
 */
class MonitorDerivedIpKindTest extends TestCase
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

    public function test_ip_with_all_monitors_bot_is_derived_as_bot(): void
    {
        $this->ipStat('1.1.1.1');

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual', 'tags' => ['scanner']]);

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertSame('bot', $row['kind']);
        $this->assertSame(['scanner'], $row['tags']);
        $this->assertSame(['human' => 0, 'bot' => 1, 'unclassified' => 0], $row['counts']);
    }

    public function test_ip_with_mixed_monitor_kinds_is_derived_as_mixed(): void
    {
        $this->ipStat('1.1.1.1');

        $botMonitor = Monitor::create(['data' => []]);
        $botMonitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $botMonitor->id, 'kind' => 'bot', 'source' => 'manual']);

        $humanMonitor = Monitor::create(['data' => []]);
        $humanMonitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $humanMonitor->id, 'kind' => 'human', 'source' => 'manual']);

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $row = collect($response->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertSame('mixed', $row['kind']);
        $this->assertSame(['human' => 1, 'bot' => 1, 'unclassified' => 0], $row['counts']);
    }

    public function test_mixed_ip_is_excluded_from_both_clean_bots_and_clean_humans(): void
    {
        $this->ipStat('1.1.1.1');

        $botMonitor = Monitor::create(['data' => []]);
        $botMonitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $botMonitor->id, 'kind' => 'bot', 'source' => 'manual']);

        $humanMonitor = Monitor::create(['data' => []]);
        $humanMonitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $humanMonitor->id, 'kind' => 'human', 'source' => 'manual']);

        $bots = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'clean_bots']);
        $humans = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'clean_humans']);

        $this->assertNotContains('1.1.1.1', collect($bots->json('data'))->pluck('ip')->all());
        $this->assertNotContains('1.1.1.1', collect($humans->json('data'))->pluck('ip')->all());
    }

    public function test_clean_unclassified_excludes_ips_with_any_classified_monitor(): void
    {
        $this->ipStat('1.1.1.1');
        $this->ipStat('2.2.2.2');

        $classified = Monitor::create(['data' => []]);
        $classified->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $classified->id, 'kind' => 'bot', 'source' => 'manual']);

        Monitor::create(['data' => []])->recordIp('2.2.2.2');

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'clean_unclassified']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('1.1.1.1', $ips);
        $this->assertContains('2.2.2.2', $ips);
    }

    public function test_kind_param_excludes_mixed_ips(): void
    {
        $this->ipStat('1.1.1.1');
        $this->ipStat('2.2.2.2');

        $mixedBot = Monitor::create(['data' => []]);
        $mixedBot->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $mixedBot->id, 'kind' => 'bot', 'source' => 'manual']);
        $mixedHuman = Monitor::create(['data' => []]);
        $mixedHuman->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $mixedHuman->id, 'kind' => 'human', 'source' => 'manual']);

        $pureBotMonitor = Monitor::create(['data' => []]);
        $pureBotMonitor->recordIp('2.2.2.2');
        MonitorLabel::create(['monitor_id' => $pureBotMonitor->id, 'kind' => 'bot', 'source' => 'manual']);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'kind' => 'bot']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('1.1.1.1', $ips);
        $this->assertContains('2.2.2.2', $ips);
    }

    public function test_tag_filter_matches_ip_via_any_of_its_monitors(): void
    {
        $this->ipStat('1.1.1.1');
        $this->ipStat('2.2.2.2');

        $tagged = Monitor::create(['data' => []]);
        $tagged->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $tagged->id, 'tags' => ['amazon']]);

        Monitor::create(['data' => []])->recordIp('2.2.2.2');

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'tag' => 'amazon']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertSame(['1.1.1.1'], $ips);
    }

    public function test_ip_with_no_classified_monitor_has_null_kind_and_empty_tags(): void
    {
        $this->ipStat('1.1.1.1');
        Monitor::create(['data' => []])->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $row = collect($response->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertNull($row['kind']);
        $this->assertSame([], $row['tags']);
        $this->assertSame(['human' => 0, 'bot' => 0, 'unclassified' => 1], $row['counts']);
    }
}
