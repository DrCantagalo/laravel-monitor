<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 258 (v0.53.0, breaking): `setIpKind`/`setIpLabels`/
 * `setIpTags` passaram a gravar em TODOS os Monitors já vistos de cada IP
 * (via `monitor_visit_ips`), não mais numa linha por IP — ver README "IP
 * classification".
 */
class MonitorIpLabelWritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_ip_kind_writes_to_every_monitor_seen_from_that_ip(): void
    {
        $monitorA = Monitor::create(['data' => []]);
        $monitorA->recordIp('1.1.1.1');

        $monitorB = Monitor::create(['data' => []]);
        $monitorB->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'setIpKind', 'ip' => '1.1.1.1', 'kind' => 'bot']);

        $response->assertOk();
        $applied = collect($response->json('applied'))->pluck('monitor_id')->sort()->values()->all();
        $this->assertSame([$monitorA->id, $monitorB->id], $applied);

        $this->assertSame('bot', MonitorLabel::where('monitor_id', $monitorA->id)->first()->kind);
        $this->assertSame('bot', MonitorLabel::where('monitor_id', $monitorB->id)->first()->kind);
    }

    public function test_an_ip_with_no_monitors_is_a_silent_no_op(): void
    {
        $response = $this->callHandler(['action' => 'setIpKind', 'ip' => '9.9.9.9', 'kind' => 'bot']);

        $response->assertOk();
        $this->assertSame([], $response->json('applied'));
        $this->assertSame([], $response->json('ignored'));
    }

    public function test_ai_source_is_ignored_per_monitor_not_per_ip(): void
    {
        $manualMonitor = Monitor::create(['data' => []]);
        $manualMonitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $manualMonitor->id, 'kind' => 'human', 'source' => 'manual', 'classified_at' => now()]);

        $freeMonitor = Monitor::create(['data' => []]);
        $freeMonitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'setIpKind', 'ip' => '1.1.1.1', 'kind' => 'bot', 'source' => 'ai']);

        $response->assertOk();
        $this->assertSame([['ip' => '1.1.1.1', 'monitor_id' => $freeMonitor->id]], $response->json('applied'));
        $this->assertSame(
            [['ip' => '1.1.1.1', 'monitor_id' => $manualMonitor->id, 'reason' => 'manual classification protected']],
            $response->json('ignored')
        );

        $this->assertSame('human', MonitorLabel::where('monitor_id', $manualMonitor->id)->first()->kind);
        $this->assertSame('bot', MonitorLabel::where('monitor_id', $freeMonitor->id)->first()->kind);
    }

    public function test_set_ip_labels_writes_tags_and_note_to_every_monitor_of_the_ip(): void
    {
        $monitorA = Monitor::create(['data' => []]);
        $monitorA->recordIp('1.1.1.1');
        $monitorB = Monitor::create(['data' => []]);
        $monitorB->recordIp('1.1.1.1');

        $response = $this->callHandler([
            'action' => 'setIpLabels',
            'ip' => '1.1.1.1',
            'tags' => ['Amazon', 'amazon', 'scanner'],
            'note' => 'shared hosting range',
        ]);

        $response->assertOk();
        $response->assertJsonPath('monitors_updated', 2);

        foreach ([$monitorA, $monitorB] as $monitor) {
            $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
            $this->assertEqualsCanonicalizing(['amazon', 'scanner'], $label->tags);
            $this->assertSame('shared hosting range', $label->note);
        }
    }

    public function test_set_ip_tags_add_and_remove_across_monitors_of_multiple_ips(): void
    {
        $monitorA = Monitor::create(['data' => []]);
        $monitorA->recordIp('1.1.1.1');
        $monitorB = Monitor::create(['data' => []]);
        $monitorB->recordIp('2.2.2.2');

        $add = $this->callHandler(['action' => 'setIpTags', 'ips' => ['1.1.1.1', '2.2.2.2'], 'tag' => 'vpn', 'op' => 'add']);
        $add->assertOk();

        $this->assertSame(['vpn'], MonitorLabel::where('monitor_id', $monitorA->id)->first()->tags);
        $this->assertSame(['vpn'], MonitorLabel::where('monitor_id', $monitorB->id)->first()->tags);

        $remove = $this->callHandler(['action' => 'setIpTags', 'ips' => ['1.1.1.1'], 'tag' => 'vpn', 'op' => 'remove']);
        $remove->assertOk();

        $this->assertNull(MonitorLabel::where('monitor_id', $monitorA->id)->first());
        $this->assertSame(['vpn'], MonitorLabel::where('monitor_id', $monitorB->id)->first()->tags);
    }

    public function test_set_ip_tags_op_remove_with_source_ai_is_rejected(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'setIpTags', 'ip' => '1.1.1.1', 'tag' => 'vpn', 'op' => 'remove', 'source' => 'ai']);

        $response->assertStatus(422);
    }

    public function test_manual_write_always_overrides_a_previous_ai_kind(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()]);

        $response = $this->callHandler(['action' => 'setIpKind', 'ip' => '1.1.1.1', 'kind' => 'human']);

        $response->assertOk();
        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('manual', $label->source);
    }
}
