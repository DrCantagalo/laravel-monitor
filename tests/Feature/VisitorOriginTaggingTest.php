<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\VisitorOriginDetector;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * laravel-monitor 319: tag de origem do visitante (first-touch) gravada
 * na criação de um Monitor novo — ver docblock de
 * `Support\VisitorOriginDetector`.
 *
 * `test_detect_*` exercitam `VisitorOriginDetector::detect()` direto
 * (lógica de classe isolada, sem precisar simular sessão/tracking
 * completo) — mesmo raciocínio de
 * `SessionVisitorTrackerAutoHumanTest`. `test_track_*` simulam o fluxo
 * completo (`SessionVisitorTracker::track()`/
 * `AnonymousVisitorTracker::track()`) pra cobrir o ponto de integração
 * real: só na criação, nunca em visita seguinte, e sem afetar
 * classificação.
 */
class VisitorOriginTaggingTest extends TestCase
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

    protected function detector(): VisitorOriginDetector
    {
        return new VisitorOriginDetector;
    }

    protected function requestWithReferer(string $referer, array $query = []): Request
    {
        return Request::create('/landing', 'GET', $query, [], [], ['HTTP_REFERER' => $referer]);
    }

    // --- Ordem de detecção ---------------------------------------------

    public function test_detect_returns_null_when_nothing_matches(): void
    {
        $request = Request::create('/landing', 'GET');

        $this->assertNull($this->detector()->detect($request));
    }

    public function test_detect_from_utm_source(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'LinkedIn']);

        $this->assertSame('linkedin', $this->detector()->detect($request));
    }

    public function test_detect_from_lm_alias_when_utm_source_is_absent(): void
    {
        $request = Request::create('/landing', 'GET', ['lm' => 'newsletter']);

        $this->assertSame('newsletter', $this->detector()->detect($request));
    }

    public function test_utm_source_wins_over_lm_when_both_are_present(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin', 'lm' => 'newsletter']);

        $this->assertSame('linkedin', $this->detector()->detect($request));
    }

    public function test_utm_source_wins_over_click_id(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin', 'gclid' => 'abc123']);

        $this->assertSame('linkedin', $this->detector()->detect($request));
    }

    public function test_detect_from_known_click_id(): void
    {
        $request = Request::create('/landing', 'GET', ['gclid' => 'abc123']);

        $this->assertSame('google', $this->detector()->detect($request));

        $request = Request::create('/landing', 'GET', ['fbclid' => 'abc123']);
        $this->assertSame('facebook', $this->detector()->detect($request));
    }

    public function test_click_id_wins_over_referer(): void
    {
        $request = Request::create('/landing', 'GET', ['fbclid' => 'abc123'], [], [], [
            'HTTP_REFERER' => 'https://www.google.com/search',
        ]);

        $this->assertSame('facebook', $this->detector()->detect($request));
    }

    public function test_detect_from_mapped_referer_domain(): void
    {
        $request = $this->requestWithReferer('https://www.linkedin.com/feed');

        $this->assertSame('linkedin', $this->detector()->detect($request));
    }

    public function test_detect_from_wildcard_referer_domain(): void
    {
        // "google.*" no config cobre google.com, google.co.uk, etc.
        $this->assertSame('google', $this->detector()->detect($this->requestWithReferer('https://www.google.com/search')));
        $this->assertSame('google', $this->detector()->detect($this->requestWithReferer('https://www.google.co.uk/search')));
    }

    public function test_detect_from_unmapped_referer_falls_back_to_registrable_domain(): void
    {
        $request = $this->requestWithReferer('https://blog.example.com/post-1');

        $this->assertSame('example.com', $this->detector()->detect($request));
    }

    public function test_self_referer_is_ignored(): void
    {
        // Request::create() sem host explícito usa "localhost".
        $request = $this->requestWithReferer('https://localhost/previous-page');

        $this->assertNull($this->detector()->detect($request));
    }

    public function test_self_referer_with_www_prefix_mismatch_is_still_ignored(): void
    {
        $request = Request::create('https://www.example.com/landing', 'GET', [], [], [], [
            'HTTP_REFERER' => 'https://example.com/previous-page',
        ]);

        $this->assertNull($this->detector()->detect($request));
    }

    public function test_no_referer_and_no_params_returns_null(): void
    {
        $request = Request::create('/landing', 'GET');

        $this->assertNull($this->detector()->detect($request));
    }

    // --- Sanitização -----------------------------------------------------

    public function test_utm_source_is_lowercased_and_stripped_of_disallowed_characters(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'Link In!! <script>']);

        $this->assertSame('linkinscript', $this->detector()->detect($request));
    }

    public function test_utm_source_over_max_tag_length_is_rejected_and_falls_through(): void
    {
        $request = Request::create('/landing', 'GET', [
            'utm_source' => str_repeat('a', MonitorLabel::MAX_TAG_LENGTH + 1),
            'gclid' => 'abc123',
        ]);

        // utm_source estourou o limite -> null -> cai pro click id.
        $this->assertSame('google', $this->detector()->detect($request));
    }

    public function test_utm_source_that_sanitizes_to_empty_falls_through_to_next_method(): void
    {
        $request = Request::create('/landing', 'GET', [
            'utm_source' => '!!!***',
            'gclid' => 'abc123',
        ]);

        $this->assertSame('google', $this->detector()->detect($request));
    }

    public function test_utm_source_cannot_produce_the_reserved_user_tag(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => MonitorLabel::TAG_USER]);

        $this->assertNull($this->detector()->detect($request));
    }

    public function test_utm_source_cannot_produce_the_reserved_user_tag_via_case_or_punctuation(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'USER!!']);

        $this->assertNull($this->detector()->detect($request));
    }

    // --- Config -----------------------------------------------------------

    public function test_origin_tagging_disabled_returns_null_even_with_a_clear_match(): void
    {
        config(['monitor.origin_tagging' => false]);

        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);

        $this->assertNull($this->detector()->detect($request));
    }

    // --- Integração: SessionVisitorTracker ------------------------------

    public function test_session_tracker_tags_a_newly_created_monitor(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);

        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $this->assertNotNull($monitor);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame(['linkedin'], $label->tags);
    }

    /**
     * Não quebrar a classificação: uma linha de MonitorLabel criada só
     * com a tag de origem continua contando como NÃO classificada.
     */
    public function test_session_tracker_origin_only_label_is_not_classified(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);

        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();

        $this->assertNull($label->kind);
        $this->assertNull($label->classified_at);
        // `source` tem default de schema 'manual' em TODA linha nova
        // (migration da tabela) — irrelevante pra classificação, que é
        // decidida só por `kind` em todo o pacote (ver
        // MonitorCategories::categoryForMonitor()). Não é um `source`
        // de classificação de verdade (nenhum código grava/lê `source`
        // sem `kind` junto).
    }

    public function test_session_tracker_does_not_tag_on_a_subsequent_visit_of_the_same_monitor(): void
    {
        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);
        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $monitorId = session('monitor_id');

        // Segunda visita, mesma sessão, origem DIFERENTE desta vez - não
        // deve mudar nada (first-touch).
        $secondRequest = Request::create('/other-page', 'GET', ['utm_source' => 'facebook']);
        app(SessionVisitorTracker::class)->track($secondRequest, new Response, '/other-page', 'test-agent', '203.0.113.9');

        $this->assertSame($monitorId, session('monitor_id'), 'mesma sessão deveria reconectar ao mesmo Monitor');

        $label = MonitorLabel::where('monitor_id', $monitorId)->first();
        $this->assertSame(['linkedin'], $label->tags, 'a tag de origem não deveria mudar numa visita seguinte');
    }

    public function test_session_tracker_does_not_tag_when_nothing_is_detected(): void
    {
        $request = Request::create('/landing', 'GET');

        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }

    public function test_session_tracker_merges_origin_tag_with_the_reserved_user_tag_on_the_same_request(): void
    {
        \Illuminate\Support\Facades\Auth::shouldReceive('check')->andReturn(true);
        \Illuminate\Support\Facades\Auth::shouldReceive('id')->andReturn(42);

        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);
        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();

        $this->assertEqualsCanonicalizing(['linkedin', 'user'], $label->tags);
        // A tag de origem não torna o Monitor classificado por si só,
        // mas maybeAutoHumanClassify() (disparado pelo login) roda na
        // mesma request e classifica normalmente.
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
    }

    // --- Integração: AnonymousVisitorTracker ----------------------------

    public function test_anonymous_tracker_tags_a_newly_created_monitor(): void
    {
        $request = Request::create('/api/ping', 'GET', ['gclid' => 'abc123']);

        app(AnonymousVisitorTracker::class)->track($request, '/api/ping', 'curl/8.0', '198.51.100.7');

        $monitorId = \Illuminate\Support\Facades\DB::table('monitor_visit_ips')->where('ip', '198.51.100.7')->value('monitor_id');
        $this->assertNotNull($monitorId);

        $label = MonitorLabel::where('monitor_id', $monitorId)->first();
        $this->assertSame(['google'], $label->tags);
    }

    public function test_anonymous_tracker_does_not_tag_on_a_subsequent_hit_from_the_same_ip(): void
    {
        $firstRequest = Request::create('/api/ping', 'GET', ['gclid' => 'abc123']);
        app(AnonymousVisitorTracker::class)->track($firstRequest, '/api/ping', 'curl/8.0', '198.51.100.7');

        $monitorId = \Illuminate\Support\Facades\DB::table('monitor_visit_ips')->where('ip', '198.51.100.7')->value('monitor_id');

        $secondRequest = Request::create('/api/other', 'GET', ['fbclid' => 'xyz987']);
        app(AnonymousVisitorTracker::class)->track($secondRequest, '/api/other', 'curl/8.0', '198.51.100.7');

        $label = MonitorLabel::where('monitor_id', $monitorId)->first();
        $this->assertSame(['google'], $label->tags, 'o mesmo Monitor (mesmo IP) não deveria ser retagueado');
    }

    // --- Monitors já existentes não são retroativamente tagueados -------

    public function test_existing_monitor_is_never_retroactively_tagged(): void
    {
        // Monitor criado ANTES desta feature existir (ou por qualquer
        // outro caminho que não passe por track()) - nunca ganha a tag
        // de origem, mesmo que receba uma visita com utm_source.
        $monitor = Monitor::create(['data' => []]);
        session(['monitor_id' => $monitor->id]);

        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);
        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }
}
