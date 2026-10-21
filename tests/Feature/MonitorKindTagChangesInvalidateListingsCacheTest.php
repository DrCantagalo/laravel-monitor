<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Facades\Monitor as MonitorFacade;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Support\ListingsCache;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * laravel-monitor 402: auditoria dos caminhos que mudam `kind`/`tags` de
 * um Monitor — `MonitorController::setMonitorKind()`/`setMonitorTags()`
 * já chamavam `invalidateListingsCache()` antes desta task (não
 * re-testado aqui, ver `MonitorSetMonitorLabelTest`). Esta task encontrou
 * DOIS caminhos que não chamavam (gap real, corrigido):
 *
 * - `Support\Monitor::markAsHuman()` (kind=human/source=verified*, fora
 *   do controller — ex: depois de um Turnstile validado pelo host app).
 * - `Support\Monitor::applyCustomTags()` (via `addTag()`/`addTags()`/
 *   `addTagForUser()`, tags custom).
 */
class MonitorKindTagChangesInvalidateListingsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'array');
    }

    protected function tearDown(): void
    {
        session()->flush();

        parent::tearDown();
    }

    protected function monitorWithSession(array $data = []): Monitor
    {
        $monitor = Monitor::create(['data' => $data]);
        session(['monitor_id' => $monitor->id]);

        return $monitor;
    }

    public function test_mark_as_human_invalidates_the_listings_cache(): void
    {
        $this->monitorWithSession();
        Cache::forever(ListingsCache::VERSION_KEY, 1);

        $this->assertTrue(MonitorFacade::markAsHuman('turnstile'));

        $this->assertSame(2, Cache::get(ListingsCache::VERSION_KEY));
    }

    public function test_mark_as_human_does_not_invalidate_when_it_is_a_silent_noop(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create([
            'monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual',
        ]);
        Cache::forever(ListingsCache::VERSION_KEY, 1);

        $this->assertFalse(MonitorFacade::markAsHuman('turnstile'));

        $this->assertSame(1, Cache::get(ListingsCache::VERSION_KEY), 'no-op não deveria bumpar a versão');
    }

    public function test_add_tags_invalidates_the_listings_cache(): void
    {
        $this->monitorWithSession();
        Cache::forever(ListingsCache::VERSION_KEY, 1);

        $this->assertTrue(MonitorFacade::addTags(['vip']));

        $this->assertSame(2, Cache::get(ListingsCache::VERSION_KEY));
    }

    public function test_add_tags_does_not_invalidate_when_nothing_valid_survives_sanitization(): void
    {
        $this->monitorWithSession();
        Cache::forever(ListingsCache::VERSION_KEY, 1);

        $this->assertFalse(MonitorFacade::addTags(['user']));

        $this->assertSame(1, Cache::get(ListingsCache::VERSION_KEY));
    }

    public function test_add_tag_for_user_invalidates_the_listings_cache(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 77]]);
        Cache::forever(ListingsCache::VERSION_KEY, 1);

        $count = MonitorFacade::addTagForUser(77, 'buyer');

        $this->assertSame(1, $count);
        $this->assertSame(2, Cache::get(ListingsCache::VERSION_KEY));
    }
}
