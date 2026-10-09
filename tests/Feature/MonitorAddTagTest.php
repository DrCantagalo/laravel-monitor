<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Facades\Monitor as MonitorFacade;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 320 (v0.66.0): `Monitor::addTag()`/`addTags()`
 * (visitante da sessão atual) e `Monitor::addTagForUser()` (sem sessão,
 * webhook/job) — tags custom de conversão em `monitor_labels.tags`,
 * nada a ver com `Monitor::tag()`/`data` (não testado aqui, ver
 * `tag()` no Support/Monitor.php).
 */
class MonitorAddTagTest extends TestCase
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

    // --- addTag()/addTags(): visitante da sessão atual ---

    public function test_add_tag_returns_false_without_an_active_monitor_session(): void
    {
        $this->assertFalse(MonitorFacade::addTag('registered'));
        $this->assertSame(0, MonitorLabel::count());
    }

    public function test_add_tag_creates_an_unclassified_label_with_the_tag(): void
    {
        $monitor = $this->monitorWithSession();

        $this->assertTrue(MonitorFacade::addTag('registered'));

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame(['registered'], $label->tags);
        $this->assertNull($label->kind);
        $this->assertNull($label->classified_at);
    }

    public function test_add_tag_merges_with_existing_tags_without_touching_kind(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual', 'tags' => ['vpn']]);

        $this->assertTrue(MonitorFacade::addTag('buyer'));

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertEqualsCanonicalizing(['vpn', 'buyer'], $label->tags);
        $this->assertSame('bot', $label->kind, 'addTag nunca classifica nem sobrescreve kind existente');
    }

    public function test_add_tag_is_idempotent(): void
    {
        $monitor = $this->monitorWithSession();

        MonitorFacade::addTag('registered');
        MonitorFacade::addTag('registered');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame(['registered'], $label->tags);
    }

    public function test_add_tags_sanitizes_case_and_disallowed_characters(): void
    {
        $monitor = $this->monitorWithSession();

        MonitorFacade::addTags([' New Client! ']);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame(['newclient'], $label->tags);
    }

    public function test_add_tag_discards_a_tag_that_is_too_long(): void
    {
        $monitor = $this->monitorWithSession();

        $result = MonitorFacade::addTag(str_repeat('a', MonitorLabel::MAX_TAG_LENGTH + 1));

        $this->assertFalse($result);
        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }

    public function test_add_tag_never_produces_the_reserved_user_tag(): void
    {
        $monitor = $this->monitorWithSession();

        $result = MonitorFacade::addTag('USER');

        $this->assertFalse($result);
        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }

    public function test_add_tags_drops_only_the_tags_exceeding_max_tags(): void
    {
        $monitor = $this->monitorWithSession();
        $existing = array_map(fn ($i) => "existing{$i}", range(1, MonitorLabel::MAX_TAGS - 1));
        MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => $existing]);

        $this->assertTrue(MonitorFacade::addTags(['one-more', 'overflow']));

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertCount(MonitorLabel::MAX_TAGS, $label->tags);
        $this->assertContains('one-more', $label->tags);
        $this->assertNotContains('overflow', $label->tags);
    }

    public function test_add_tags_returns_false_when_nothing_survives_sanitization(): void
    {
        $this->monitorWithSession();

        $this->assertFalse(MonitorFacade::addTags(['!!!', 'user']));
    }

    // --- addTagForUser(): sem sessão (webhook/job) ---

    public function test_add_tag_for_user_returns_zero_when_user_has_no_monitors(): void
    {
        $this->assertSame(0, MonitorFacade::addTagForUser(999, 'buyer'));
    }

    public function test_add_tag_for_user_tags_every_monitor_linked_to_the_user(): void
    {
        $a = Monitor::create(['data' => ['user_id' => 42]]);
        $b = Monitor::create(['data' => ['user_id' => 42]]);
        Monitor::create(['data' => ['user_id' => 7]]);

        $count = MonitorFacade::addTagForUser(42, 'buyer');

        $this->assertSame(2, $count);
        $this->assertSame(['buyer'], MonitorLabel::where('monitor_id', $a->id)->first()->tags);
        $this->assertSame(['buyer'], MonitorLabel::where('monitor_id', $b->id)->first()->tags);
    }

    public function test_add_tag_for_user_accepts_a_model_like_object_via_get_key(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 15]]);

        $fakeUser = new class
        {
            public function getKey()
            {
                return 15;
            }
        };

        $count = MonitorFacade::addTagForUser($fakeUser, 'buyer');

        $this->assertSame(1, $count);
        $this->assertSame(['buyer'], MonitorLabel::where('monitor_id', $monitor->id)->first()->tags);
    }

    public function test_add_tag_for_user_is_idempotent_across_concurrent_callers(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 42]]);

        MonitorFacade::addTagForUser(42, 'buyer');
        $count = MonitorFacade::addTagForUser(42, 'buyer');

        $this->assertSame(1, $count);
        $this->assertSame(['buyer'], MonitorLabel::where('monitor_id', $monitor->id)->first()->tags);
    }

    public function test_add_tag_for_user_accepts_multiple_tags_at_once(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 42]]);

        MonitorFacade::addTagForUser(42, ['buyer', 'vip']);

        $this->assertEqualsCanonicalizing(['buyer', 'vip'], MonitorLabel::where('monitor_id', $monitor->id)->first()->tags);
    }

    public function test_add_tag_for_user_returns_zero_for_a_non_numeric_user(): void
    {
        $this->assertSame(0, MonitorFacade::addTagForUser('not-a-user', 'buyer'));
    }

    public function test_add_tag_for_user_never_classifies_the_monitor(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 42]]);

        MonitorFacade::addTagForUser(42, 'buyer');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNull($label->kind);
        $this->assertNull($label->classified_at);
    }
}
