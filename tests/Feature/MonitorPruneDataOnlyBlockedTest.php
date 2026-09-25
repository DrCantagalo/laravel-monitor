<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * laravel-monitor 242 (v0.50.0), breaking: a action HTTP `pruneData` não
 * aceita mais `only_blocked` — sempre roda a varredura completa
 * (`DataPruner::prune($olderThanDays, false)`). Um `only_blocked` que
 * ainda venha no body de um cliente desatualizado é silenciosamente
 * ignorado, nunca rejeitado com 422. `--only-blocked` continua existindo
 * normalmente no comando `monitor:prune` (não coberto aqui — ver
 * `Support\DataPruner::prune()`, cujo parâmetro `$onlyBlocked` não foi
 * removido).
 */
class MonitorPruneDataOnlyBlockedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    protected function seedOldMonitor(string $ip): Monitor
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp($ip);

        $monitor->timestamps = false;
        $monitor->updated_at = Carbon::now()->subDays(30);
        $monitor->save();

        return $monitor;
    }

    protected function seedOldIpStat(string $ip): IpStat
    {
        $stat = IpStat::create([
            'ip' => $ip,
            'visit_count' => 1,
            'first_seen' => Carbon::now()->subDays(30),
            'last_seen' => Carbon::now()->subDays(30),
            'flagged' => false,
        ]);

        $stat->timestamps = false;
        $stat->save();

        return $stat;
    }

    public function test_only_blocked_true_in_body_is_silently_ignored_and_full_sweep_still_runs(): void
    {
        $blockedIp = '1.1.1.1';
        $cleanIp = '2.2.2.2';

        $this->seedOldMonitor($blockedIp);
        $this->seedOldMonitor($cleanIp);
        $this->seedOldIpStat($blockedIp);
        $this->seedOldIpStat($cleanIp);

        BlockedIp::create(['ip' => $blockedIp, 'source' => 'manual']);

        // Cliente desatualizado ainda manda only_blocked=true, esperando o
        // comportamento antigo (só apagaria o IP bloqueado). A partir da
        // 0.50.0 isso é ignorado: os dois IPs (bloqueado E limpo) são
        // apagados, igual a only_blocked=false.
        $response = $this->callHandler([
            'action' => 'pruneData',
            'older_than_days' => 1,
            'only_blocked' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('monitors_deleted', 2);
        $response->assertJsonPath('ip_stats_deleted', 2);

        $this->assertSame(0, Monitor::count());
        $this->assertSame(0, IpStat::count());
    }

    public function test_only_blocked_1_string_in_body_is_also_ignored(): void
    {
        $this->seedOldMonitor('3.3.3.3');

        $response = $this->callHandler([
            'action' => 'pruneData',
            'older_than_days' => 1,
            'only_blocked' => '1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertSame(0, Monitor::count());
    }

    public function test_no_422_just_because_of_a_stray_only_blocked_param(): void
    {
        $response = $this->callHandler([
            'action' => 'pruneData',
            'older_than_days' => 1,
            'only_blocked' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_older_than_days_is_still_required_and_validated(): void
    {
        $response = $this->callHandler([
            'action' => 'pruneData',
            'only_blocked' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }
}
