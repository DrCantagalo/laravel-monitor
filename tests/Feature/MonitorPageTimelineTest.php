<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * laravel-monitor 286 (v0.60.0): novo read action `getPageTimeline` —
 * série diária zero-filled de hits de UM path específico, soma de todos
 * os `Monitor`. Mesma validação/janela/cache de `getTimeline`.
 */
class MonitorPageTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function hitOnDay(Monitor $monitor, string $day, string $path = 'example.com/a'): void
    {
        Carbon::setTestNow(Carbon::parse($day.' 12:00:00'));
        $monitor->recordHit($path);
    }

    public function test_requires_path(): void
    {
        $response = $this->callHandler(['action' => 'getPageTimeline']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_validates_days_range(): void
    {
        $this->callHandler(['action' => 'getPageTimeline', 'path' => 'example.com/a', 'days' => 6])
            ->assertStatus(422);

        $this->callHandler(['action' => 'getPageTimeline', 'path' => 'example.com/a', 'days' => 366])
            ->assertStatus(422);

        $this->callHandler(['action' => 'getPageTimeline', 'path' => 'example.com/a', 'days' => 'abc'])
            ->assertStatus(422);
    }

    public function test_zero_filled_daily_series_summed_across_monitors(): void
    {
        $a = Monitor::create(['data' => []]);
        $b = Monitor::create(['data' => []]);

        $this->hitOnDay($a, '2026-10-02');
        $this->hitOnDay($b, '2026-10-02');
        $this->hitOnDay($a, '2026-10-04');

        // outro path não deve contar na série de 'example.com/a'.
        $this->hitOnDay($a, '2026-10-04', 'example.com/other');

        Carbon::setTestNow(Carbon::create(2026, 10, 4, 23, 0, 0, 'UTC'));

        $response = $this->callHandler(['action' => 'getPageTimeline', 'path' => 'example.com/a', 'days' => 7]);

        $response->assertOk();
        $response->assertJsonPath('path', 'example.com/a');

        $byDay = array_combine($response->json('days'), $response->json('hits'));

        $this->assertSame(2, $byDay['2026-10-02']);
        $this->assertSame(0, $byDay['2026-10-03']);
        $this->assertSame(1, $byDay['2026-10-04']);
    }

    public function test_exact_since_present_in_response(): void
    {
        $response = $this->callHandler(['action' => 'getPageTimeline', 'path' => 'example.com/a']);

        $response->assertOk();
        $this->assertArrayHasKey('exact_since', $response->json());
    }
}
