<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Support\PathsAuditor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * laravel-monitor 339: `PathsAuditor::compiledRoutes()` passa a excluir
 * qualquer rota catch-all por comportamento (regex que casa paths-sonda
 * aleatórios), não só `Route::fallback()` — bug real no ed.eco.br, onde
 * `Route::domain('www.vai.ed.eco.br')->any('{any?}', ...)->where('any', '.*')`
 * (redirect www → canônico) "cobria" os 117 paths de `monitor_paths`
 * porque só a flag `isFallback` era checada.
 */
class PathsAuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_catch_all_route_without_domain_is_excluded(): void
    {
        Route::any('{any?}', fn () => 'ok')->where('any', '.*');

        MonitorPath::create(['path' => 'old-campaign-link', 'status' => 'trap']);
        MonitorPath::create(['path' => 'another/old/path', 'status' => 'safe']);

        $this->assertSame([], PathsAuditor::audit());
    }

    public function test_catch_all_route_with_domain_is_still_excluded(): void
    {
        // O bloqueio em si ignora domínio (`MonitorMethod::isPathBlocked()`),
        // então o audit precisa espelhar essa mesma superfície de risco:
        // uma rota catch-all com `domain()` ainda "cobre" o path em
        // qualquer domínio atendido pela installation.
        Route::domain('www.example.test')->any('{any?}', fn () => 'ok')->where('any', '.*');

        MonitorPath::create(['path' => 'old-campaign-link', 'status' => 'trap']);

        $this->assertSame([], PathsAuditor::audit());
    }

    public function test_restricted_dynamic_route_still_generates_a_finding(): void
    {
        Route::get('posts/{id}', fn () => 'ok')->where('id', '[0-9]+');

        MonitorPath::create(['path' => 'posts/42', 'status' => 'trap']);

        $findings = PathsAuditor::audit();

        $this->assertCount(1, $findings);
        $this->assertSame('posts/42', $findings[0]['path']);
        $this->assertSame('route_table', $findings[0]['matched_route']['source']);
    }

    public function test_static_real_route_still_generates_a_finding(): void
    {
        Route::get('contact', fn () => 'ok');

        MonitorPath::create(['path' => 'contact', 'status' => 'trap']);

        $findings = PathsAuditor::audit();

        $this->assertCount(1, $findings);
        $this->assertSame('contact', $findings[0]['path']);
    }

    public function test_fallback_route_is_still_excluded(): void
    {
        Route::fallback(fn () => 'ok');

        MonitorPath::create(['path' => 'old-campaign-link', 'status' => 'trap']);

        $this->assertSame([], PathsAuditor::audit());
    }
}
