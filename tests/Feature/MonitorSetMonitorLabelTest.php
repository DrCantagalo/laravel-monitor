<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 258 (v0.53.0): novas write actions `setMonitorKind`/
 * `setMonitorTags` — classificam/tagueiam UM Monitor direto por
 * `monitor_id`. Ver README "IP classification".
 *
 * laravel-monitor 295 (v0.61.0): desde que `setIpKind`/`setIpLabels`/
 * `setIpTags` foram removidas (classificação por IP vazava entre
 * pessoas/dispositivos diferentes atrás do mesmo IP compartilhado),
 * `setMonitorKind`/`setMonitorTags` são as ÚNICAS write actions de
 * classificação que restam. Este arquivo também cobre: as três actions
 * removidas de fato não existem mais, e a tag reservada `user` não pode
 * ser escrita manualmente via `setMonitorTags`. Naquela versão
 * `setMonitorKind` só aceitava `source=manual` (gap temporário).
 *
 * laravel-monitor 303 (v0.62.0): `setMonitorKind` volta a aceitar
 * `source=ai`, com a mesma proteção contra sobrescrever uma classificação
 * manual que `setIpKind` tinha antes de ser removida.
 */
class MonitorSetMonitorLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * laravel-monitor 295 (v0.61.0, breaking): sem camada de
     * compatibilidade — chamar qualquer uma das três actions removidas
     * cai no `default` do switch de `handle()`, igual qualquer action
     * desconhecida (`400`, `"Invalid action"`).
     */
    public function test_removed_ip_write_actions_fall_back_to_invalid_action(): void
    {
        config(['monitor.local_token' => 'test-local-token']);

        foreach (['setIpKind', 'setIpLabels', 'setIpTags'] as $action) {
            $response = $this->callHandler(['action' => $action, 'ip' => '1.1.1.1', 'kind' => 'bot', 'tag' => 'vpn']);

            $response->assertStatus(400);
            $response->assertJsonPath('success', false);
            $response->assertJsonPath('message', 'Invalid action');
        }
    }

    /**
     * laravel-monitor 295 (v0.61.0): a tag reservada `user` (gerenciada só
     * pelo pacote, conforme `data.user_id`) não pode ser adicionada
     * manualmente via `setMonitorTags` — rejeitada com `422`, nunca
     * silenciosamente ignorada.
     */
    public function test_rejects_manually_adding_the_reserved_user_tag(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'setMonitorTags', 'monitor_id' => $monitor->id, 'tag' => 'user', 'op' => 'add']);

        $response->assertStatus(422);
        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }

    /**
     * Mesma rejeição pra `op=remove` — mesmo que o Monitor já tenha a tag
     * (ex: autenticado de verdade), ninguém pode removê-la manualmente.
     */
    public function test_rejects_manually_removing_the_reserved_user_tag(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 1]]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => ['user']]);

        $response = $this->callHandler(['action' => 'setMonitorTags', 'monitor_id' => $monitor->id, 'tag' => 'user', 'op' => 'remove']);

        $response->assertStatus(422);
        $this->assertSame(['user'], MonitorLabel::where('monitor_id', $monitor->id)->first()->tags);
    }

    /**
     * Case-insensitive: `normalizeTags()` já lowercase antes da checagem
     * de reserva, então `USER`/`User` são rejeitados do mesmo jeito.
     */
    public function test_rejects_the_reserved_user_tag_regardless_of_case(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'setMonitorTags', 'monitor_id' => $monitor->id, 'tag' => 'USER', 'op' => 'add']);

        $response->assertStatus(422);
    }

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

    /**
     * laravel-monitor 303 (v0.62.0): `source=ai` agora é aceito (fecha o
     * gap aberto na 295 - ver docblock de `setMonitorKind`). Num Monitor
     * sem `kind` ainda, grava normal.
     */
    public function test_accepts_source_ai_on_a_monitor_with_no_prior_kind(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'ai']);

        $response->assertOk();
        $response->assertJsonPath('applied', true);
        $response->assertJsonPath('kind', 'human');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('ai', $label->source);
        $this->assertSame('human', $label->kind);
    }

    /**
     * Mesma proteção que `setIpKind` tinha antes de ser removida: uma
     * escrita `source=ai` nunca sobrescreve um `kind` já definido por
     * `source=manual` no mesmo Monitor.
     */
    public function test_source_ai_never_overwrites_an_existing_manual_kind(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual', 'classified_at' => now()]);

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'ai']);

        $response->assertOk();
        $response->assertJsonPath('applied', false);
        $response->assertJsonPath('kind', 'bot');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('manual', $label->source);
        $this->assertSame('bot', $label->kind);
    }

    /**
     * Ao contrário: uma escrita `source=manual` sempre vence, mesmo sobre
     * um `kind` já definido por `source=ai`.
     */
    public function test_manual_always_overwrites_an_existing_ai_kind(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()]);

        $response = $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'human']);

        $response->assertOk();
        $response->assertJsonPath('applied', true);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('manual', $label->source);
        $this->assertSame('human', $label->kind);
    }

    /**
     * Qualquer valor de `source` fora de `ai` (incluindo ausente) cai no
     * default `manual` - mesma normalização que `setIpKind` tinha.
     */
    public function test_unknown_source_value_falls_back_to_manual(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $this->callHandler(['action' => 'setMonitorKind', 'monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'bogus']);

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
