<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * laravel-monitor 383: `date_to` sem hora (ex: "2026-10-18", o formato
 * mandado pelo `<input type="date">` do dashboard) excluía o último dia
 * inteiro em `getVisitorsByIp` (`last_seen`), `filter=blocked`
 * (`last_offense_at`) e `getMonitorQueue` (`created_at`) — o MySQL lê a
 * string sem hora como `00:00:00`, então qualquer linha daquele mesmo
 * dia tinha timestamp maior e ficava fora do `<=`. Os testes rodam em
 * SQLite (comparação lexicográfica de texto), onde o mesmo bug reproduz:
 * "2026-10-18 10:00:00" > "2026-10-18" na ordenação de string.
 */
class MonitorDateToEndOfDayTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_visitors_today_to_today_includes_ip_seen_today(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 15, 30, 0));

        IpStat::create([
            'ip' => '1.2.3.4',
            'visit_count' => 1,
            'first_seen' => now(),
            'last_seen' => now(),
            'flagged' => false,
        ]);

        $response = $this->callHandler([
            'action' => 'getVisitorsByIp',
            'date_from' => '2026-10-18',
            'date_to' => '2026-10-18',
            'include_without_monitors' => true,
        ]);

        $response->assertOk();
        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertContains('1.2.3.4', $ips, 'date_to=hoje sem hora não pode excluir um IpStat visto hoje');
    }

    public function test_blocked_visitors_today_to_today_includes_ip_blocked_today(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 15, 30, 0));

        BlockedIp::create([
            'ip' => '9.9.9.9',
            'strike_count' => 1,
            'lifetime_offense_count' => 1,
            'last_offense_at' => now(),
        ]);

        $response = $this->callHandler([
            'action' => 'getVisitorsByIp',
            'filter' => 'blocked',
            'date_from' => '2026-10-18',
            'date_to' => '2026-10-18',
        ]);

        $response->assertOk();
        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertContains('9.9.9.9', $ips, 'date_to=hoje sem hora não pode excluir um BlockedIp com last_offense_at hoje');
    }

    public function test_monitor_queue_today_to_today_includes_monitor_created_today(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 15, 30, 0));

        $monitor = Monitor::create(['data' => []]);

        $response = $this->callHandler([
            'action' => 'getMonitorQueue',
            'group' => 'all',
            'date_from' => '2026-10-18',
            'date_to' => '2026-10-18',
        ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($monitor->id, $ids, 'date_to=hoje sem hora não pode excluir um Monitor criado hoje');
    }

    /**
     * Último dia de um intervalo (não só "hoje a hoje") também precisa
     * entrar.
     */
    public function test_last_day_of_a_multi_day_range_is_included(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 9, 0, 0));

        $monitor = Monitor::create(['data' => []]);
        $monitor->forceFill(['created_at' => Carbon::create(2026, 10, 10, 23, 0, 0)])->save();

        $response = $this->callHandler([
            'action' => 'getMonitorQueue',
            'group' => 'all',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-10',
        ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($monitor->id, $ids, 'date_to=último dia do intervalo (sem hora) não pode excluir um registro daquele mesmo dia');
    }

    /**
     * `date_to` com hora explícita continua respeitado como está —
     * normalizeDateTimeBoundary() não deve mexer nele.
     */
    public function test_date_to_with_explicit_hour_is_not_pushed_to_end_of_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 15, 0, 0));

        $early = Monitor::create(['data' => []]);
        $early->forceFill(['created_at' => Carbon::create(2026, 10, 18, 8, 0, 0)])->save();

        $late = Monitor::create(['data' => []]);
        $late->forceFill(['created_at' => Carbon::create(2026, 10, 18, 20, 0, 0)])->save();

        $response = $this->callHandler([
            'action' => 'getMonitorQueue',
            'group' => 'all',
            'date_from' => '2026-10-18 00:00:00',
            'date_to' => '2026-10-18 12:00:00',
        ]);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($early->id, $ids);
        $this->assertNotContains($late->id, $ids, 'date_to com hora explícita não pode virar fim do dia (23:59:59)');
    }
}
