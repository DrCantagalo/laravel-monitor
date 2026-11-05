<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 448 (origem: home-page 444 — ordenar visitantes por
 * última visita, aba Visitors do dashboard). `getVisitorsByIp` não tinha
 * parâmetro de ordenação: `buildVisitorsResult()` sempre ordenava por
 * `flagged` (true primeiro), desempatado por `visits`. `sort=last_seen` +
 * `order=asc|desc` agora substitui essa ordem por completo; sem `sort`
 * (ou um valor não reconhecido) o comportamento fica EXATAMENTE igual a
 * antes desta task.
 */
class MonitorVisitorsSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    // `include_without_monitors` default false exclui da listagem qualquer
    // IP sem nenhum Monitor atual (ver buildVisitorsResult/laravel-monitor
    // 350) — por isso cada IpStat aqui também ganha um Monitor via
    // recordIp(), mesmo padrão de MonitorVisitorByIpVisitsTest.
    protected function ipStat(string $ip, string $lastSeen, bool $flagged = false): void
    {
        IpStat::create([
            'ip' => $ip,
            'visit_count' => 1,
            'first_seen' => $lastSeen,
            'last_seen' => $lastSeen,
            'flagged' => $flagged,
        ]);

        Monitor::create(['data' => []])->recordIp($ip);
    }

    public function test_sort_last_seen_desc_orders_most_recent_first(): void
    {
        // flagged=true de propósito em '1.1.1.3' — sem o fix isso viraria
        // o primeiro da lista (ordem default); com sort=last_seen deixa de
        // importar.
        $this->ipStat('1.1.1.1', now()->subDays(3));
        $this->ipStat('1.1.1.2', now()->subDays(1));
        $this->ipStat('1.1.1.3', now()->subDays(5), flagged: true);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'sort' => 'last_seen', 'order' => 'desc']);

        $response->assertOk();
        $this->assertSame(
            ['1.1.1.2', '1.1.1.1', '1.1.1.3'],
            collect($response->json('data'))->pluck('ip')->all()
        );
    }

    public function test_sort_last_seen_asc_orders_oldest_first(): void
    {
        $this->ipStat('1.1.1.1', now()->subDays(3));
        $this->ipStat('1.1.1.2', now()->subDays(1));
        $this->ipStat('1.1.1.3', now()->subDays(5));

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'sort' => 'last_seen', 'order' => 'asc']);

        $response->assertOk();
        $this->assertSame(
            ['1.1.1.3', '1.1.1.1', '1.1.1.2'],
            collect($response->json('data'))->pluck('ip')->all()
        );
    }

    public function test_without_sort_param_the_default_ordering_is_unchanged(): void
    {
        $this->ipStat('1.1.1.1', now()->subDays(3), flagged: true);
        $this->ipStat('1.1.1.2', now()->subDays(1));

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $response->assertOk();
        // Flagged primeiro, mesmo comportamento de antes desta task.
        $this->assertSame('1.1.1.1', $response->json('data.0.ip'));
    }

    public function test_unknown_sort_value_falls_back_to_the_default_ordering(): void
    {
        $this->ipStat('1.1.1.1', now()->subDays(3), flagged: true);
        $this->ipStat('1.1.1.2', now()->subDays(1));

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'sort' => 'visits']);

        $response->assertOk();
        $this->assertSame('1.1.1.1', $response->json('data.0.ip'));
    }
}
