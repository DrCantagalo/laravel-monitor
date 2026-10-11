<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Facades\Monitor as MonitorFacade;
use Drcantagalo\LaravelMonitor\Http\Middleware\AvoidMonitor;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\PendingCustomTags;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 331: antes desta task, `Monitor::addTags()` chamado
 * ANTES de `session('monitor_id')` existir (primeira request de um
 * visitante novo, se o controller/rota chama `addTag()` antes de
 * `MonitorMethod` criar/achar o Monitor na "lógica de volta") sempre
 * retornava `false` e perdia a tag silenciosamente - bloqueava a task
 * "home-page 330" (rota `/go/{origin}` marcando a tag de origem já na
 * primeira request).
 *
 * Esta suíte cobre o ponto de integração real: `Monitor::addTag()`
 * chamado (simulando o controller/rota, "lógica de ida") seguido do
 * `track()` do tracker correspondente (simulando a "lógica de volta" de
 * `MonitorMethod::handle()`) - mesmo padrão de
 * `VisitorOriginTaggingTest`/`SessionVisitorTrackerAutoHumanTest`
 * (chamar os trackers direto via `app(...)`, sem precisar de uma rota
 * HTTP real).
 */
class PendingCustomTagsTest extends TestCase
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

    // --- Visitante novo (sessão): addTag antes do Monitor existir ------

    public function test_session_tracker_applies_a_pending_tag_queued_before_the_monitor_existed(): void
    {
        $this->assertNull(session('monitor_id'));

        // "Lógica de ida": o controller/rota chama addTag() antes de
        // MonitorMethod ter criado/achado o Monitor desta sessão.
        $this->assertTrue(MonitorFacade::addTag('registered'));
        $this->assertSame(0, MonitorLabel::count(), 'nada deveria ser gravado ainda - o Monitor nem existe');

        // "Lógica de volta": SessionVisitorTracker::track() cria o
        // Monitor desta request (primeira visita) e deve consumir a tag
        // pendente.
        $request = Request::create('/go/linkedin', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/go/linkedin', 'test-agent', '203.0.113.9');

        $monitorId = session('monitor_id');
        $this->assertNotNull($monitorId);

        $label = MonitorLabel::where('monitor_id', $monitorId)->first();
        $this->assertNotNull($label);
        $this->assertSame(['registered'], $label->tags);
        $this->assertNull($label->kind, 'addTag pendente nunca classifica o Monitor');

        // A lista pendente foi consumida - não sobra nada pra uma
        // próxima request vazar.
        $this->assertTrue(app(PendingCustomTags::class)->isEmpty());
    }

    public function test_session_tracker_merges_a_pending_tag_with_the_automatic_origin_tag_on_the_same_request(): void
    {
        $this->assertTrue(MonitorFacade::addTag('registered'));

        $request = Request::create('/landing', 'GET', ['utm_source' => 'linkedin']);
        app(SessionVisitorTracker::class)->track($request, new Response, '/landing', 'test-agent', '203.0.113.9');

        $label = MonitorLabel::where('monitor_id', session('monitor_id'))->first();
        $this->assertEqualsCanonicalizing(['linkedin', 'registered'], $label->tags, 'a tag pendente do controller e a de origem automática devem coexistir');
    }

    public function test_session_tracker_applies_a_pending_tag_when_the_monitor_is_found_via_remember_cookie_not_created(): void
    {
        // Visitante que já tinha um Monitor (cookie de remember-me), mas
        // cuja sessão PHP expirou - session('monitor_id') ainda não
        // existe nesta request nova, mesmo o Monitor já existindo.
        $existing = Monitor::create(['data' => [], 'id_token' => 'remember-token-123']);

        $this->assertNull(session('monitor_id'));
        $this->assertTrue(MonitorFacade::addTag('registered'));

        $request = Request::create('/some-page', 'GET', [], ['monitor_id_token' => 'remember-token-123']);
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $this->assertSame($existing->id, session('monitor_id'), 'deveria ter reconectado ao Monitor existente, não criado um novo');

        $label = MonitorLabel::where('monitor_id', $existing->id)->first();
        $this->assertNotNull($label);
        $this->assertSame(['registered'], $label->tags);
    }

    // --- Visitante com Monitor já existente: comportamento de hoje -----

    public function test_add_tag_applies_directly_without_the_pending_path_when_a_monitor_session_is_already_active(): void
    {
        $monitor = Monitor::create(['data' => []]);
        session(['monitor_id' => $monitor->id]);

        $this->assertTrue(MonitorFacade::addTag('registered'));

        // Aplicado direto - nada ficou pendente.
        $this->assertTrue(app(PendingCustomTags::class)->isEmpty());

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame(['registered'], $label->tags);
    }

    // --- Tracking não roda de verdade: pendente é descartado ------------

    public function test_pending_tag_is_discarded_without_error_on_a_route_with_avoid_monitor(): void
    {
        $request = Request::create('/internal/ping', 'GET');

        // AvoidMonitor roda ANTES de $next($request) no pipeline real -
        // seta a mesma flag de sessão que SessionVisitorTracker::track()
        // lê e descarta.
        app(AvoidMonitor::class)->handle($request, fn ($req) => $req);

        $this->assertTrue(MonitorFacade::addTag('registered'));
        $this->assertFalse(app(PendingCustomTags::class)->isEmpty(), 'a tag fica pendente até o tracker rodar (ou não)');

        app(SessionVisitorTracker::class)->track($request, new Response, '/internal/ping', 'test-agent', '203.0.113.9');

        $this->assertNull(session('monitor_id'), 'avoid-monitor não deveria ter criado nenhum Monitor');
        $this->assertSame(0, Monitor::count());
        $this->assertSame(0, MonitorLabel::count());
    }

    public function test_pending_tag_is_discarded_without_error_when_skip_tracking_is_called_explicitly(): void
    {
        $request = Request::create('/ajax/internal', 'GET');

        $this->assertTrue(MonitorFacade::addTag('registered'));
        MonitorFacade::skipTracking();

        app(SessionVisitorTracker::class)->track($request, new Response, '/ajax/internal', 'test-agent', '203.0.113.9');

        $this->assertNull(session('monitor_id'));
        $this->assertSame(0, Monitor::count());
        $this->assertSame(0, MonitorLabel::count());
    }

    // --- AnonymousVisitorTracker (sem sessão) ---------------------------

    public function test_anonymous_tracker_applies_a_pending_tag_on_a_newly_created_monitor(): void
    {
        $this->assertTrue(MonitorFacade::addTag('registered'));

        $request = Request::create('/api/ping', 'GET');
        app(AnonymousVisitorTracker::class)->track($request, '/api/ping', 'curl/8.0', '198.51.100.7');

        $monitorId = DB::table('monitor_visit_ips')->where('ip', '198.51.100.7')->value('monitor_id');
        $this->assertNotNull($monitorId);

        $label = MonitorLabel::where('monitor_id', $monitorId)->first();
        $this->assertNotNull($label);
        $this->assertSame(['registered'], $label->tags);
    }

    public function test_anonymous_tracker_applies_a_pending_tag_when_the_monitor_is_found_via_ip_not_created(): void
    {
        $firstRequest = Request::create('/api/ping', 'GET');
        app(AnonymousVisitorTracker::class)->track($firstRequest, '/api/ping', 'curl/8.0', '198.51.100.7');
        $monitorId = DB::table('monitor_visit_ips')->where('ip', '198.51.100.7')->value('monitor_id');

        $this->assertTrue(MonitorFacade::addTag('registered'));

        $secondRequest = Request::create('/api/other', 'GET');
        app(AnonymousVisitorTracker::class)->track($secondRequest, '/api/other', 'curl/8.0', '198.51.100.7');

        $label = MonitorLabel::where('monitor_id', $monitorId)->first();
        $this->assertSame(['registered'], $label->tags);
    }
}
