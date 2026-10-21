<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Support\BlockResultsListCache;
use Drcantagalo\LaravelMonitor\Support\ListingsCache;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 402: nova action `clearCache` do handler — bump dos
 * contadores de versão existentes (`monitor:pages:version`/
 * `monitor:listings:version`, via `ListingsCache`/
 * `invalidatePagesCache()`) + o contador NOVO `BlockResultsListCache`
 * (`getBlockResults`/`getApiBlockEvents`, fora do esquema versionado de
 * sempre) + as chaves FIXAS de "data totals"/block-results total. Mesma
 * auth dos outros handlers de escrita (`local_token` só).
 */
class MonitorClearCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    // --- Auth ---

    public function test_rejects_an_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'clearCache']);

        $response->assertStatus(401);
    }

    public function test_rejects_a_read_token(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'clearCache'], $token);

        $response->assertStatus(401);
    }

    public function test_accepts_the_local_token(): void
    {
        $response = $this->callHandler(['action' => 'clearCache']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // --- Version counters actually increment ---

    public function test_bumps_the_pages_version_counter(): void
    {
        Cache::forever('monitor:pages:version', 1);

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $this->assertSame(2, Cache::get('monitor:pages:version'));
    }

    public function test_bumps_the_listings_version_counter(): void
    {
        Cache::forever(ListingsCache::VERSION_KEY, 1);

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $this->assertSame(2, Cache::get(ListingsCache::VERSION_KEY));
    }

    public function test_bumps_the_block_results_list_version_counter(): void
    {
        Cache::forever(BlockResultsListCache::VERSION_KEY, 1);

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $this->assertSame(2, Cache::get(BlockResultsListCache::VERSION_KEY));
    }

    public function test_initializes_counters_that_were_never_set_before(): void
    {
        // Instalação nova: nenhum dos 3 contadores foi incrementado ainda
        // (nenhuma action de escrita rodou) — clearCache não pode falhar
        // só porque a chave não existe ainda.
        $response = $this->callHandler(['action' => 'clearCache']);

        $response->assertOk();
        $this->assertSame(2, Cache::get('monitor:pages:version'));
        $this->assertSame(2, Cache::get(ListingsCache::VERSION_KEY));
        $this->assertSame(2, Cache::get(BlockResultsListCache::VERSION_KEY));
    }

    // --- The next read recomputes instead of serving stale data ---

    public function test_get_table_stats_recomputes_after_clear_cache_instead_of_serving_a_stale_cached_result(): void
    {
        Monitor::create(['data' => []]);

        $first = $this->callHandler(['action' => 'getTableStats']);
        $this->assertSame(1, collect($first->json('data'))->firstWhere('table', 'monitors')['rows']);

        DB::table('monitors')->insert(['data' => null, 'created_at' => now(), 'updated_at' => now()]);

        $stale = $this->callHandler(['action' => 'getTableStats']);
        $this->assertSame(1, collect($stale->json('data'))->firstWhere('table', 'monitors')['rows'], 'ainda deveria vir do cache');

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $fresh = $this->callHandler(['action' => 'getTableStats']);
        $this->assertSame(2, collect($fresh->json('data'))->firstWhere('table', 'monitors')['rows'], 'clearCache deveria ter invalidado o cache de listagens');
    }

    public function test_get_block_results_recomputes_after_clear_cache(): void
    {
        DB::table('monitor_block_results')->insert(['ip' => '203.0.113.1', 'counter' => 1, 'last_attempt_at' => now()]);

        $first = $this->callHandler(['action' => 'getBlockResults']);
        $this->assertCount(1, $first->json('data'));

        DB::table('monitor_block_results')->insert(['ip' => '203.0.113.2', 'counter' => 1, 'last_attempt_at' => now()]);

        $stale = $this->callHandler(['action' => 'getBlockResults']);
        $this->assertCount(1, $stale->json('data'), 'ainda deveria vir do cache (TTL curto, sem versão até agora)');

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $fresh = $this->callHandler(['action' => 'getBlockResults']);
        $this->assertCount(2, $fresh->json('data'), 'clearCache deveria ter invalidado a listagem paginada de block-results');
    }

    public function test_get_api_block_events_recomputes_after_clear_cache(): void
    {
        DB::table('monitor_api_block_events')->insert([
            'ip' => '203.0.113.9', 'message' => 'request bloqueada com token válido',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = $this->callHandler(['action' => 'getApiBlockEvents']);
        $this->assertCount(1, $first->json('data'));

        DB::table('monitor_api_block_events')->insert([
            'ip' => '203.0.113.10', 'message' => 'request bloqueada com token válido',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $stale = $this->callHandler(['action' => 'getApiBlockEvents']);
        $this->assertCount(1, $stale->json('data'));

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $fresh = $this->callHandler(['action' => 'getApiBlockEvents']);
        $this->assertCount(2, $fresh->json('data'));
    }

    public function test_get_data_totals_recompute_after_clear_cache(): void
    {
        Monitor::create(['data' => []]);

        $first = $this->callHandler(['action' => 'getData']);
        $this->assertSame(1, $first->json('visitors_total'));

        DB::table('monitors')->insert(['data' => null, 'created_at' => now(), 'updated_at' => now()]);

        $stale = $this->callHandler(['action' => 'getData']);
        $this->assertSame(1, $stale->json('visitors_total'), 'ainda deveria vir do cache curto de data totals');

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $fresh = $this->callHandler(['action' => 'getData']);
        $this->assertSame(2, $fresh->json('visitors_total'), 'clearCache deveria ter invalidado monitor:data:monitors-by-kind');
    }

    public function test_blocked_attempts_total_recomputes_after_clear_cache(): void
    {
        DB::table('monitor_block_results')->insert(['ip' => '203.0.113.3', 'counter' => 5, 'last_attempt_at' => now()]);

        $first = $this->callHandler(['action' => 'getData']);
        $this->assertSame(5, $first->json('blocked_attempts_total'));

        DB::table('monitor_block_results')->where('ip', '203.0.113.3')->update(['counter' => 9]);

        $stale = $this->callHandler(['action' => 'getData']);
        $this->assertSame(5, $stale->json('blocked_attempts_total'), 'ainda deveria vir do cache');

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $fresh = $this->callHandler(['action' => 'getData']);
        $this->assertSame(9, $fresh->json('blocked_attempts_total'));
    }

    // --- Must NOT touch the middleware's block caches or the read-token cache ---

    public function test_does_not_unblock_an_actively_blocked_ip(): void
    {
        Cache::put('monitor:blocked-ip:203.0.113.4', true, 60);
        BlockedIp::create(['ip' => '203.0.113.4', 'source' => 'manual']);

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $this->assertTrue(Cache::get('monitor:blocked-ip:203.0.113.4'));
    }

    public function test_does_not_clear_the_blocked_path_cache(): void
    {
        Cache::put('monitor:blocked-path:trap', true, 60);

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $this->assertTrue(Cache::get('monitor:blocked-path:trap'));
    }

    public function test_does_not_clear_the_read_token_cache(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $this->callHandler(['action' => 'clearCache'])->assertOk();

        $this->assertTrue(Cache::has("monitor:read-token:{$token}"));
    }

    // --- No cooldown/throttle ---

    public function test_can_be_called_repeatedly_without_any_throttling(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->callHandler(['action' => 'clearCache'])->assertOk();
        }
    }
}
