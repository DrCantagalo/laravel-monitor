<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * laravel-monitor 435: `SessionVisitorTracker::applyAuthenticatedUserFields()`
 * copia nome/e-mail (config `track_authenticated_user_fields`) do usuário
 * autenticado (`Auth::user()`) pra `data`, nos dois call sites de `track()`
 * que já gravam `data['user_id']` — resolve a aba Users do dashboard ficar
 * sem nome quando o host app nunca chama `Monitor::tag()` no evento `Login`
 * (ou quando o login é por remember-me/sessão já viva, que nunca dispara
 * esse evento).
 */
class SessionVisitorTrackerAuthenticatedUserFieldsTest extends TestCase
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

    /**
     * Objeto simples, sem __get/dynamic properties — propositalmente pra
     * exercitar o caso de atributo configurado que não existe nele (o
     * acesso devolve null via `??`, nunca lança).
     */
    protected function fakeAuthenticatedUser(?string $name = 'Jane Doe', ?string $email = 'jane@example.com'): object
    {
        return new class($name, $email)
        {
            public function __construct(public ?string $name, public ?string $email) {}
        };
    }

    protected function mockAuth(int $userId, object $authUser): void
    {
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn($userId);
        Auth::shouldReceive('user')->andReturn($authUser);
    }

    public function test_track_copies_name_and_email_when_creating_a_new_monitor_on_authenticated_login(): void
    {
        $this->mockAuth(42, $this->fakeAuthenticatedUser('Jane Doe', 'jane@example.com'));

        $request = Request::create('/some-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $this->assertSame('Jane Doe', $monitor->data['name']);
        $this->assertSame('jane@example.com', $monitor->data['email']);
        $this->assertSame(42, $monitor->data['user_id']);
    }

    public function test_track_copies_name_and_email_when_the_monitor_is_found_via_remember_cookie(): void
    {
        $existing = Monitor::create(['data' => [], 'id_token' => 'remember-token-123']);

        $this->mockAuth(7, $this->fakeAuthenticatedUser('Carlos Souza', 'carlos@example.com'));

        $request = Request::create('/some-page', 'GET', [], ['monitor_id_token' => 'remember-token-123']);
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $this->assertSame($existing->id, session('monitor_id'), 'deveria ter reconectado ao Monitor existente, não criado um novo');

        $existing->refresh();
        $this->assertSame('Carlos Souza', $existing->data['name']);
        $this->assertSame('carlos@example.com', $existing->data['email']);
    }

    public function test_track_fills_name_on_the_next_request_for_an_already_authenticated_monitor_with_no_name_yet(): void
    {
        // Sessão já autenticada antes desta feature existir: user_id já
        // gravado, mas sem name/email (ex: só veio de uma request antiga,
        // ou de um login que nunca passou por Monitor::tag()).
        $monitor = Monitor::create(['data' => ['user_id' => 7]]);
        session(['monitor_id' => $monitor->id]);

        $this->mockAuth(7, $this->fakeAuthenticatedUser('Carlos Souza', 'carlos@example.com'));

        $request = Request::create('/other-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/other-page', 'test-agent', '203.0.113.9');

        $monitor->refresh();
        $this->assertSame('Carlos Souza', $monitor->data['name']);
        $this->assertSame('carlos@example.com', $monitor->data['email']);
    }

    public function test_track_updates_name_when_it_changes_on_the_authenticated_user(): void
    {
        $monitor = Monitor::create(['data' => ['user_id' => 7, 'name' => 'Old Name', 'email' => 'old@example.com']]);
        session(['monitor_id' => $monitor->id]);

        $this->mockAuth(7, $this->fakeAuthenticatedUser('New Name', 'new@example.com'));

        $request = Request::create('/other-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/other-page', 'test-agent', '203.0.113.9');

        $monitor->refresh();
        $this->assertSame('New Name', $monitor->data['name']);
        $this->assertSame('new@example.com', $monitor->data['email']);
    }

    public function test_track_does_not_copy_any_field_when_config_is_an_empty_array(): void
    {
        config(['monitor.track_authenticated_user_fields' => []]);

        $this->mockAuth(42, $this->fakeAuthenticatedUser('Jane Doe', 'jane@example.com'));

        $request = Request::create('/some-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $this->assertArrayNotHasKey('name', $monitor->data->getArrayCopy());
        $this->assertArrayNotHasKey('email', $monitor->data->getArrayCopy());
        // user_id continua sendo gravado - o gate de [] é só da cópia de
        // campos, não do tracking de autenticação em si.
        $this->assertSame(42, $monitor->data['user_id']);
    }

    public function test_track_ignores_a_configured_attribute_that_does_not_exist_on_the_authenticated_user_without_throwing(): void
    {
        config(['monitor.track_authenticated_user_fields' => ['name' => 'name', 'phone' => 'phone']]);

        $this->mockAuth(42, $this->fakeAuthenticatedUser('Jane Doe', 'jane@example.com'));

        $request = Request::create('/some-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $this->assertSame('Jane Doe', $monitor->data['name']);
        $this->assertArrayNotHasKey('phone', $monitor->data->getArrayCopy());
    }

    public function test_track_does_not_copy_fields_when_track_authenticated_user_is_disabled(): void
    {
        config(['monitor.track_authenticated_user' => false]);

        $this->mockAuth(42, $this->fakeAuthenticatedUser('Jane Doe', 'jane@example.com'));

        $request = Request::create('/some-page', 'GET');
        app(SessionVisitorTracker::class)->track($request, new Response, '/some-page', 'test-agent', '203.0.113.9');

        $monitor = Monitor::find(session('monitor_id'));
        $this->assertArrayNotHasKey('name', $monitor->data->getArrayCopy());
        $this->assertArrayNotHasKey('user_id', $monitor->data->getArrayCopy());
    }
}
