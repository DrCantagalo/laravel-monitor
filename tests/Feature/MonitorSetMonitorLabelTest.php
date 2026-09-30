<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 258 (v0.53.0): novas write actions `setMonitorKind`/
 * `setMonitorTags` — classificam/tagueiam UM Monitor direto por
 * `monitor_id`, sempre `source=manual`. Ver README "IP classification".
 */
class MonitorSetMonitorLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_kind_on_a_monitor(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'bot']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('kind', 'bot');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('bot', $label->kind);
        $this->assertSame('manual', $label->source);
        $this->assertNotNull($label->classified_at);
    }

    public function test_ignores_source_param_and_always_writes_manual(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'ai']);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('manual', $label->source);
    }

    public function test_clearing_kind_to_null_prunes_an_otherwise_empty_row(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual', 'classified_at' => now()]);

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => null]);

        $response->assertOk();
        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }

    public function test_invalid_kind_returns_422(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'alien']);

        $response->assertStatus(422);
    }

    public function test_nonexistent_monitor_id_returns_422(): void
    {
        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => 999, 'kind' => 'bot']);

        $response->assertStatus(422);
    }

    public function test_missing_monitor_id_returns_422(): void
    {
        $response = $this->callHandler(['action' => 'setMonitorTags', 'tag' => 'amazon']);

        $response->assertStatus(422);
    }

    public function test_adds_and_removes_a_tag_on_a_monitor(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $add = $this->callHandler(['action' => 'setMonitorTags', 'monitor_id' => $monitor->id, 'tag' => 'Amazon', 'op' => 'add']);
        $add->assertOk();

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame(['amazon'], $label->tags);

        $remove = $this->callHandler(['action' => 'setMonitorTags', 'monitor_id' => $monitor->id, 'tag' => 'amazon', 'op' => 'remove']);
        $remove->assertOk();

        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }

    public function test_empty_tag_returns_422(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'setMonitorTags', 'monitor_id' => $monitor->id, 'tag' => '   ']);

        $response->assertStatus(422);
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->postJson('/monitor/handler', ['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'bot']);

        $response->assertStatus(401);
    }

    public function test_ephemeral_read_token_cannot_call_set_monitor_kind(): void
    {
        $monitor = Monitor::create(['data' => []]);
        config(['monitor.local_token' => 'test-local-token']);

        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'bot'], $token);

        $response->assertStatus(401);
    }

    public function test_cascade_delete_removes_the_label_when_the_monitor_is_deleted(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual']);

        $monitor->delete();

        $this->assertSame(0, MonitorLabel::count());
    }

    /**
     * laravel-monitor 258 (v0.53.0): decisão deliberada do usuário — ao
     * contrário de `monitor_ip_labels` (uma classificação `source=manual`
     * sobrevivia indefinidamente a um IP nunca mais visto), a partir desta
     * versão um `clearData` (ou o prune) apaga o Monitor e, via
     * `cascadeOnDelete`, o rótulo junto — mesmo `source=manual`.
     */
    public function test_clear_data_deletes_manual_labels_along_with_their_monitor(): void
    {
        config(['monitor.local_token' => 'test-local-token']);

        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'manual', 'classified_at' => now()]);

        $response = $this->callHandler(['action' => 'clearData']);

        $response->assertOk();
        $this->assertSame(0, MonitorLabel::count());
    }

    public function test_prune_data_deletes_labels_of_pruned_monitors_including_manual(): void
    {
        config(['monitor.local_token' => 'test-local-token']);

        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'manual', 'classified_at' => now()]);

        $monitor->timestamps = false;
        $monitor->updated_at = now()->subDays(30);
        $monitor->save();

        $response = $this->callHandler(['action' => 'pruneData', 'older_than_days' => 1]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertSame(0, MonitorLabel::count());
    }
}
