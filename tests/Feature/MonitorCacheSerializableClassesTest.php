<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 454: até a v0.79.0, `getBlockedPaths`/`getBlockedIps`/
 * `getMonitorVisits`/`getBlockResults`/`getApiBlockEvents` guardavam
 * `$paginator->items()` CRU (objetos Eloquent) dentro do valor cacheado
 * por `Cache::remember`, e `buildVisitorsResult`/`buildBlockedVisitorsResult`/
 * `buildUsersResult` guardavam a `Illuminate\Support\Collection` inteira em
 * vez de um array puro. Com `config('cache.serializable_classes') === false`
 * (default novo do Laravel 13, presente em amb/360gradi.net-2.0/
 * amariaastrologa), o cache store faz `unserialize($payload, ['allowed_classes'
 * => false])` no HIT — isso desmonta QUALQUER objeto (não só Eloquent;
 * `stdClass`/`Collection` também) em `__PHP_Incomplete_Class`, perdendo os
 * atributos protegidos e devolvendo linhas sem `path`/`ip` etc. no HIT,
 * mesmo com o MISS respondendo normal (não passa por serialize/unserialize).
 *
 * Estes testes usam o store `file` (serializa de verdade — `array`, usado
 * no resto da suíte, não serializa por padrão, então não pegaria esta
 * classe de bug) com `cache.serializable_classes` desligado, chamam a
 * mesma action duas vezes (1a = miss, popula o cache; 2a = hit, passa por
 * unserialize) e conferem que a resposta do hit é IDÊNTICA à do miss.
 */
class MonitorCacheSerializableClassesTest extends TestCase
{
    use RefreshDatabase;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePath = sys_get_temp_dir().'/laravel-monitor-cache-test-'.bin2hex(random_bytes(8));

        config([
            'monitor.local_token' => 'test-local-token',
            'cache.default' => 'file',
            'cache.stores.file.path' => $this->cachePath,
            'cache.stores.file.lock_path' => $this->cachePath,
            // Causa raiz da laravel-monitor 454 — ver docblock desta classe.
            'cache.serializable_classes' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cachePath);

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /**
     * Chama a mesma action duas vezes (miss, depois hit) e confere que as
     * duas respostas são byte-a-byte o mesmo JSON.
     */
    private function assertMissAndHitMatch(array $params): array
    {
        $miss = $this->callHandler($params);
        $miss->assertOk();

        $hit = $this->callHandler($params);
        $hit->assertOk();

        $this->assertSame(
            $miss->getContent(),
            $hit->getContent(),
            'resposta do cache HIT deve ser idêntica à do MISS (regressão laravel-monitor 454: '.
            'serializable_classes=false desmontava models/Collection guardados cru no cache)'
        );

        return $hit->json();
    }

    public function test_get_blocked_paths_survives_cache_hit_with_serializable_classes_off(): void
    {
        MonitorPath::create(['path' => 'example.com/trap', 'status' => 'trap']);

        $json = $this->assertMissAndHitMatch(['action' => 'getBlockedPaths']);

        $this->assertCount(1, $json['data']);
        $this->assertSame('example.com/trap', $json['data'][0]['path']);
    }

    public function test_get_blocked_ips_survives_cache_hit_with_serializable_classes_off(): void
    {
        BlockedIp::create(['ip' => '203.0.113.9', 'source' => 'manual']);

        $json = $this->assertMissAndHitMatch(['action' => 'getBlockedIps']);

        $this->assertCount(1, $json['data']);
        $this->assertSame('203.0.113.9', $json['data'][0]['ip']);
    }

    public function test_get_visitors_by_ip_survives_cache_hit_with_serializable_classes_off(): void
    {
        IpStat::create([
            'ip' => '198.51.100.7',
            'visit_count' => 3,
            'first_seen' => now(),
            'last_seen' => now(),
            'flagged' => false,
        ]);
        // buildVisitorsResult() exclui IPs sem nenhum Monitor atual por
        // padrão (include_without_monitors=false) — ver docblock lá.
        Monitor::create(['data' => []])->recordIp('198.51.100.7');

        $json = $this->assertMissAndHitMatch(['action' => 'getVisitorsByIp']);

        $row = collect($json['data'])->firstWhere('ip', '198.51.100.7');
        $this->assertNotNull($row, 'linha do IP desapareceu no cache HIT');
        $this->assertSame(3, $row['lifetime_visits']);
    }

    public function test_get_visitors_by_ip_blocked_filter_survives_cache_hit_with_serializable_classes_off(): void
    {
        BlockedIp::create(['ip' => '198.51.100.8', 'source' => 'manual']);

        $json = $this->assertMissAndHitMatch(['action' => 'getVisitorsByIp', 'filter' => 'blocked']);

        $row = collect($json['data'])->firstWhere('ip', '198.51.100.8');
        $this->assertNotNull($row, 'linha do IP bloqueado desapareceu no cache HIT (buildBlockedVisitorsResult)');
    }

    public function test_get_pages_survives_cache_hit_with_serializable_classes_off(): void
    {
        Monitor::create(['data' => []])->recordHit('example.com/a');

        $json = $this->assertMissAndHitMatch(['action' => 'getPages', 'filter' => 'all']);

        $this->assertCount(1, $json['data']);
        $this->assertSame('example.com/a', $json['data'][0]['path']);
    }

    public function test_get_users_survives_cache_hit_with_serializable_classes_off(): void
    {
        Monitor::create(['data' => ['user_id' => 42, 'name' => 'Fulano', 'email' => 'fulano@example.com']]);

        $json = $this->assertMissAndHitMatch(['action' => 'getUsers']);

        $row = collect($json['data'])->firstWhere('user_id', 42);
        $this->assertNotNull($row, 'linha do usuário desapareceu no cache HIT (buildUsersResult)');
    }

    public function test_get_monitor_visits_survives_cache_hit_with_serializable_classes_off(): void
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('203.0.113.10');

        $json = $this->assertMissAndHitMatch(['action' => 'getMonitorVisits', 'monitor_id' => $monitor->id]);

        $this->assertArrayHasKey('data', $json);
    }

    public function test_get_block_results_survives_cache_hit_with_serializable_classes_off(): void
    {
        DB::table('monitor_block_results')->insert([
            'ip' => '203.0.113.11',
            'counter' => 5,
            'last_attempt_at' => now(),
        ]);

        $json = $this->assertMissAndHitMatch(['action' => 'getBlockResults']);

        $this->assertCount(1, $json['data']);
        $this->assertSame('203.0.113.11', $json['data'][0]['ip']);
    }
}
