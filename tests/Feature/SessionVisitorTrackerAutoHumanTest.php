<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * laravel-monitor 284 (v0.59.0): auto-classificação `kind=human,
 * source=auth` quando `SessionVisitorTracker` detecta `data['user_id']`
 * pela primeira vez (ver `SessionVisitorTracker::maybeAutoHumanClassify()`).
 *
 * Duas frentes:
 * - `test_*_classify` chamam `maybeAutoHumanClassify()` direto via
 *   Reflection — é lógica de classe isolada (regras de quando
 *   criar/preservar o `kind`), não precisa simular sessão/Auth HTTP pra
 *   validar. Mesmo raciocínio de "testes no pacote são unitários/de classe
 *   isolada" do README interno do projeto (`TASKS.md`, seção
 *   laravel-monitor).
 * - `test_track_*` simulam o fluxo completo (`track()`, sessão array em
 *   memória, `Auth` mockado) pra cobrir o *gate* real: só classifica na
 *   transição guest->autenticado, e só com `track_authenticated_user`
 *   ligado. O fluxo HTTP ponta-a-ponta de verdade (middleware real,
 *   login real) continua sendo validado no harness.
 */
class SessionVisitorTrackerAutoHumanTest extends TestCase
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

    protected function callMaybeAutoHumanClassify(int $monitorId): void
    {
        $tracker = new SessionVisitorTracker;
        $method = new \ReflectionMethod($tracker, 'maybeAutoHumanClassify');
        $method->setAccessible(true);
        $method->invoke($tracker, $monitorId);
    }

    public function test_classify_creates_human_auth_label_when_monitor_has_no_label_row(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $this->callMaybeAutoHumanClassify($monitor->id);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
        $this->assertNotNull($label->classified_at);
    }

    public function test_classify_fills_kind_when_label_row_exists_without_kind(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => ['vpn']]);

        $this->callMaybeAutoHumanClassify($monitor->id);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
        $this->assertSame(['vpn'], $label->tags);
    }

    public function test_classify_never_overwrites_an_existing_bot_classification(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual']);

        $this->callMaybeAutoHumanClassify($monitor->id);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('bot', $label->kind);
        $this->assertSame('manual', $label->source);
    }

    public function test_classify_never_overwrites_an_existing_manual_human_classification_source(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'manual']);

        $this->callMaybeAutoHumanClassify($monitor->id);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('manual', $label->source, 'source=manual não pode virar auth');
    }

    public function test_classify_is_idempotent(): void
    {
        $monitor = Monitor::create(['data' => []]);

        $this->callMaybeAutoHumanClassify($monitor->id);
        $firstClassifiedAt = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        sleep(0);
        $this->callMaybeAutoHumanClassify($monitor->id);
        $secondClassifiedAt = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        $this->assertSame(1, MonitorLabel::where('monitor_id', $monitor->id)->count());
        $this->assertEquals($firstClassifiedAt, $secondClassifiedAt);
    }

    protected function trackAuthenticatedRequest(int $userId): Monitor
    {
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn($userId);

        $request = Request::create('/some-page', 'GET');
        $response = new Response;

        app(SessionVisitorTracker::class)->track($request, $response, '/some-page', 'test-agent', '203.0.113.9');

        return Monitor::find(session('monitor_id'));
    }

    public function test_track_classifies_new_monitor_as_human_auth_on_first_authenticated_visit(): void
    {
        $monitor = $this->trackAuthenticatedRequest(42);

        $this->assertNotNull($monitor);
        $this->assertSame(42, $monitor->data['user_id']);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
    }

    public function test_track_classifies_existing_guest_monitor_on_the_request_it_first_logs_in(): void
    {
        $monitor = Monitor::create(['data' => []]);
        session(['monitor_id' => $monitor->id]);

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn(7);

        $request = Request::create('/some-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertNotNull($label);
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
    }

    public function test_track_does_not_reclassify_on_subsequent_requests_once_already_authenticated(): void
    {
        $monitor = $this->trackAuthenticatedRequest(42);
        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $label->update(['kind' => 'bot', 'source' => 'manual']);

        // Mesma sessão, Monitor já com user_id gravado - uma reclassificação
        // indevida aqui sobrescreveria a decisão manual (bot) tomada depois
        // da primeira visita autenticada.
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn(42);
        $request = Request::create('/other-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/other-page', 'test-agent', '203.0.113.9');

        $label->refresh();
        $this->assertSame('bot', $label->kind, 'request repetida não deveria ter sequer tentado reclassificar');
        $this->assertSame('manual', $label->source);
    }

    public function test_track_does_not_classify_when_track_authenticated_user_is_disabled(): void
    {
        config(['monitor.track_authenticated_user' => false]);

        $monitor = $this->trackAuthenticatedRequest(42);

        $this->assertArrayNotHasKey('user_id', $monitor->data->getArrayCopy());
        $this->assertNull(MonitorLabel::where('monitor_id', $monitor->id)->first());
    }
}
