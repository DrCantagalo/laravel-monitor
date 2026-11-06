<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 455: `getAllTrapPaths` (export não paginado) +
 * `addTrapPaths` (add-only em lote) — a dupla que substitui o sync
 * paginado de trap paths por um sync por diff (home-page 456).
 */
class MonitorTrapPathsDiffSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_all_trap_paths_returns_every_trap_path_unpaginated_ordered_by_id(): void
    {
        $first = MonitorPath::create(['path' => 'example.com/trap-a', 'status' => 'trap']);
        MonitorPath::create(['path' => 'example.com/safe-a', 'status' => 'safe']);
        $second = MonitorPath::create(['path' => 'example.com/trap-b', 'status' => 'trap']);

        $response = $this->callHandler(['action' => 'getAllTrapPaths']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('truncated', false);
        $this->assertSame(['example.com/trap-a', 'example.com/trap-b'], $response->json('data'));
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_get_all_trap_paths_is_truncated_past_the_configured_limit(): void
    {
        config(['monitor.trap_paths_export_limit' => 2]);

        MonitorPath::create(['path' => 'example.com/trap-1', 'status' => 'trap']);
        MonitorPath::create(['path' => 'example.com/trap-2', 'status' => 'trap']);
        MonitorPath::create(['path' => 'example.com/trap-3', 'status' => 'trap']);

        $response = $this->callHandler(['action' => 'getAllTrapPaths']);

        $response->assertOk();
        $response->assertJsonPath('truncated', true);
        $this->assertCount(2, $response->json('data'));
        // Corta as mais recentes (maior id), nunca as mais antigas.
        $this->assertSame(['example.com/trap-1', 'example.com/trap-2'], $response->json('data'));
    }

    public function test_get_all_trap_paths_requires_read_or_local_token(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'getAllTrapPaths']);

        $response->assertStatus(401);
    }

    public function test_add_trap_paths_inserts_new_paths_as_trap(): void
    {
        $response = $this->callHandler([
            'action' => 'addTrapPaths',
            'paths' => ['example.com/new-trap-1', 'example.com/new-trap-2'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame(['example.com/new-trap-1', 'example.com/new-trap-2'], $response->json('applied'));
        $this->assertSame(0, $response->json('already_trap'));
        $this->assertSame(0, $response->json('skipped_safe'));
        $this->assertSame(0, $response->json('skipped_clean'));

        $this->assertDatabaseHas('monitor_paths', ['path' => 'example.com/new-trap-1', 'status' => 'trap']);
        $this->assertDatabaseHas('monitor_paths', ['path' => 'example.com/new-trap-2', 'status' => 'trap']);
    }

    public function test_add_trap_paths_counts_already_trap_without_duplicating(): void
    {
        MonitorPath::create(['path' => 'example.com/already-trap', 'status' => 'trap']);

        $response = $this->callHandler([
            'action' => 'addTrapPaths',
            'paths' => ['example.com/already-trap'],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('applied'));
        $this->assertSame(1, $response->json('already_trap'));
        $this->assertSame(1, MonitorPath::where('path', 'example.com/already-trap')->count());
    }

    public function test_add_trap_paths_never_overwrites_a_safe_path(): void
    {
        MonitorPath::create(['path' => 'example.com/already-safe', 'status' => 'safe']);

        $response = $this->callHandler([
            'action' => 'addTrapPaths',
            'paths' => ['example.com/already-safe'],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('applied'));
        $this->assertSame(1, $response->json('skipped_safe'));
        $this->assertDatabaseHas('monitor_paths', ['path' => 'example.com/already-safe', 'status' => 'safe']);
    }

    public function test_add_trap_paths_skips_a_path_the_site_actually_serves_clean(): void
    {
        // not_found=false (default) — mesmo critério 'clean' de getPages:
        // o site respondeu 2xx pra este path, nunca 404.
        Monitor::create(['data' => []])->recordHit('example.com/real-page');

        $response = $this->callHandler([
            'action' => 'addTrapPaths',
            'paths' => ['example.com/real-page'],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('applied'));
        $this->assertSame(1, $response->json('skipped_clean'));
        $this->assertDatabaseMissing('monitor_paths', ['path' => 'example.com/real-page']);
    }

    public function test_add_trap_paths_applies_a_path_that_was_only_ever_seen_as_404(): void
    {
        // not_found=true — o site NUNCA serviu 2xx pra este path, então
        // o critério 'clean' não se aplica e ele vira trap normalmente.
        Monitor::create(['data' => []])->recordHit('example.com/honeypot', true);

        $response = $this->callHandler([
            'action' => 'addTrapPaths',
            'paths' => ['example.com/honeypot'],
        ]);

        $response->assertOk();
        $this->assertSame(['example.com/honeypot'], $response->json('applied'));
        $this->assertDatabaseHas('monitor_paths', ['path' => 'example.com/honeypot', 'status' => 'trap']);
    }

    public function test_add_trap_paths_handles_a_large_batch_across_chunk_boundaries(): void
    {
        // ADD_TRAP_PATHS_CHUNK = 500 — lote maior que um chunk, com os
        // três desfechos (novo/already_trap/skipped_safe) espalhados
        // pelas duas metades, pra provar que o chunking não perde nada
        // nem mistura lotes.
        MonitorPath::create(['path' => 'example.com/bulk-already-trap', 'status' => 'trap']);
        MonitorPath::create(['path' => 'example.com/bulk-already-safe', 'status' => 'safe']);

        $paths = ['example.com/bulk-already-trap', 'example.com/bulk-already-safe'];

        for ($i = 0; $i < 1200; $i++) {
            $paths[] = "example.com/bulk-new-{$i}";
        }

        $response = $this->callHandler(['action' => 'addTrapPaths', 'paths' => $paths]);

        $response->assertOk();
        $this->assertSame(1, $response->json('already_trap'));
        $this->assertSame(1, $response->json('skipped_safe'));
        $this->assertCount(1200, $response->json('applied'));
        $this->assertSame(1200, MonitorPath::where('status', 'trap')->where('path', 'like', 'example.com/bulk-new-%')->count());
        $this->assertSame(1202, MonitorPath::count(), 'nenhuma linha extra/duplicada deveria ter sido criada');
    }

    public function test_add_trap_paths_rejects_empty_input(): void
    {
        $response = $this->callHandler(['action' => 'addTrapPaths', 'paths' => []]);

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
    }

    public function test_add_trap_paths_requires_local_token_not_read_token(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->postJson('/monitor/handler', [
            'action' => 'addTrapPaths',
            'paths' => ['example.com/x'],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(401);
        $this->assertDatabaseMissing('monitor_paths', ['path' => 'example.com/x']);
    }
}
