<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorVisit;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * laravel-monitor 350 (v0.71.0): `getVisitorsByIp` passa a derivar
 * `visits` dos Monitors ATUAIS do IP (em vez do `visit_count` vitalício
 * de `monitor_ip_stats`, que nunca é decrementado por design — ver
 * README "Per-IP stats"/"Data cleanup"). Cenário original (sessão
 * direta 2026-10-14): depois de um prune no ed.eco.br, um IP mostrava
 * `visit_count=186` mas só tinha 11 Monitors de poucas visitas cada.
 */
class MonitorVisitorByIpVisitsTest extends TestCase
{
    use RefreshDatabase;

    protected function ipStat(string $ip, int $visitCount = 1): void
    {
        IpStat::create([
            'ip' => $ip,
            'visit_count' => $visitCount,
            'first_seen' => now(),
            'last_seen' => now(),
            'flagged' => false,
        ]);
    }

    protected function monitorWithVisits(string $ip, int $visits): Monitor
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp($ip);

        for ($i = 0; $i < $visits; $i++) {
            MonitorVisit::create(['monitor_id' => $monitor->id, 'paths' => ['/'], 'scraper' => false]);
        }

        return $monitor;
    }

    public function test_visits_sums_monitor_visits_across_every_monitor_of_the_ip(): void
    {
        $this->ipStat('1.1.1.1', 186);
        $this->monitorWithVisits('1.1.1.1', 2);
        $this->monitorWithVisits('1.1.1.1', 3);

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $row = collect($response->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertSame(5, $row['visits']);
        $this->assertSame(186, $row['lifetime_visits']);
        // Deprecated, mas ainda presente com o mesmo valor do vitalício.
        $this->assertSame(186, $row['visit_count']);
    }

    public function test_monitor_without_any_recorded_visit_counts_as_zero_not_excluded(): void
    {
        $this->ipStat('1.1.1.1', 10);
        // recordIp sem nenhum MonitorVisit — mesmo caminho do
        // AnonymousVisitorTracker, que nunca grava monitor_visits (ver
        // Support\VisitRecorder).
        Monitor::create(['data' => []])->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertContains('1.1.1.1', $ips);
        $row = collect($response->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertSame(0, $row['visits']);
    }

    public function test_ip_without_any_current_monitor_is_hidden_by_default(): void
    {
        $this->ipStat('2.2.2.2', 50);
        // Nenhum Monitor/monitor_visit_ips pra este IP — simula o estado
        // pós-prune (stats sobrevivem, Monitor já foi apagado).

        $this->ipStat('1.1.1.1', 10);
        $this->monitorWithVisits('1.1.1.1', 1);

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('2.2.2.2', $ips);
        $this->assertContains('1.1.1.1', $ips);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_include_without_monitors_brings_back_ip_with_zero_visits(): void
    {
        $this->ipStat('2.2.2.2', 50);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'include_without_monitors' => true]);

        $row = collect($response->json('data'))->firstWhere('ip', '2.2.2.2');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['visits']);
        $this->assertSame(50, $row['lifetime_visits']);
    }

    public function test_visits_drops_after_monitor_deleted_but_lifetime_visits_does_not(): void
    {
        $this->ipStat('1.1.1.1', 186);
        $keep = $this->monitorWithVisits('1.1.1.1', 2);
        $gone = $this->monitorWithVisits('1.1.1.1', 9);

        $before = $this->callHandler(['action' => 'getVisitorsByIp']);
        $this->assertSame(11, collect($before->json('data'))->firstWhere('ip', '1.1.1.1')['visits']);

        // Mesma garantia da task 350: prune/delete de Monitor nunca
        // decrementa monitor_ip_stats.visit_count. `delete()` direto
        // (em vez de clearData/pruneData/monitor:prune, que já chamam
        // `ListingsCache::invalidate()` dentro de `DataPruner` — ver
        // `src/Support/DataPruner.php`) não invalida o cache de listagem
        // sozinho, então flush manual aqui só pra isolar a lógica de
        // derivação em si, sem testar o mecanismo de invalidação (já
        // coberto por outros testes do `DataPruner`/`clearData`).
        $gone->delete();
        Cache::flush();

        $after = $this->callHandler(['action' => 'getVisitorsByIp']);
        $row = collect($after->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertSame(2, $row['visits']);
        $this->assertSame(186, $row['lifetime_visits']);
        $this->assertSame(186, IpStat::visitCount('1.1.1.1'));

        // IP ainda tem 1 Monitor (`$keep`) — segue elegível por padrão.
        $this->assertNotNull($row);
        $this->assertTrue($keep->exists);
    }

    public function test_ip_disappears_once_its_last_monitor_is_deleted(): void
    {
        $this->ipStat('1.1.1.1', 186);
        $only = $this->monitorWithVisits('1.1.1.1', 4);

        $only->delete();

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);
        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertNotContains('1.1.1.1', $ips);

        // Mas com include_without_monitors=true ele volta, com visits=0
        // e o vitalício intacto.
        $withAll = $this->callHandler(['action' => 'getVisitorsByIp', 'include_without_monitors' => true]);
        $row = collect($withAll->json('data'))->firstWhere('ip', '1.1.1.1');
        $this->assertSame(0, $row['visits']);
        $this->assertSame(186, $row['lifetime_visits']);
    }

    public function test_default_ordering_is_by_visits_desc_not_lifetime_visits(): void
    {
        // IP com vitalício maior mas poucos Monitors/visitas atuais.
        $this->ipStat('1.1.1.1', 1000);
        $this->monitorWithVisits('1.1.1.1', 1);

        // IP com vitalício menor mas mais Monitors/visitas atuais.
        $this->ipStat('2.2.2.2', 10);
        $this->monitorWithVisits('2.2.2.2', 7);

        $response = $this->callHandler(['action' => 'getVisitorsByIp']);

        $ips = collect($response->json('data'))->pluck('ip')->all();
        $this->assertSame(['2.2.2.2', '1.1.1.1'], $ips);
    }

    public function test_blocked_filter_includes_visits_and_ignores_include_without_monitors(): void
    {
        BlockedIp::create(['ip' => '3.3.3.3', 'strike_count' => 1, 'lifetime_offense_count' => 1, 'last_offense_at' => now()]);
        $this->ipStat('3.3.3.3', 42);
        $this->monitorWithVisits('3.3.3.3', 6);

        // IP bloqueado sem nenhum Monitor atual (ex: já podado) continua
        // aparecendo — não é afetado por include_without_monitors, ver
        // README "Paginated visitor/blocklist listing".
        BlockedIp::create(['ip' => '4.4.4.4', 'strike_count' => 1, 'lifetime_offense_count' => 1, 'last_offense_at' => now()]);

        $response = $this->callHandler(['action' => 'getVisitorsByIp', 'filter' => 'blocked']);

        $row = collect($response->json('data'))->firstWhere('ip', '3.3.3.3');
        $this->assertSame(6, $row['visits']);
        $this->assertSame(42, $row['lifetime_visits']);

        $rowWithoutMonitor = collect($response->json('data'))->firstWhere('ip', '4.4.4.4');
        $this->assertNotNull($rowWithoutMonitor);
        $this->assertSame(0, $rowWithoutMonitor['visits']);
        $this->assertNull($rowWithoutMonitor['lifetime_visits']);
    }

    /**
     * Guarda de performance (laravel-monitor 350, item 4): o número de
     * queries SQL disparadas por `getVisitorsByIp` não pode crescer com
     * o número de IPs/Monitors na página — a agregação de `visits` é um
     * JOIN/GROUP BY em SQL (`visitsAggregateSubquery()`), nunca uma
     * query por linha.
     */
    public function test_listing_query_count_does_not_grow_with_the_number_of_ips(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $ip = "10.0.0.{$i}";
            $this->ipStat($ip, 5);
            $this->monitorWithVisits($ip, 2);
        }

        DB::enableQueryLog();
        $this->callHandler(['action' => 'getVisitorsByIp', 'per_page' => 50]);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        // Desliga antes de semear mais dados — sem isso as próprias
        // queries de setup (insert por IP/Monitor/visita) entravam no
        // log e inflavam $largeCount sem relação com a query real da
        // listagem.
        DB::disableQueryLog();

        for ($i = 4; $i <= 33; $i++) {
            $ip = "10.0.0.{$i}";
            $this->ipStat($ip, 5);
            $this->monitorWithVisits($ip, 2);
        }

        // Sem isso, o segundo `callHandler` com os mesmos parâmetros
        // bateria no cache de listagem (`Cache::remember`) e não
        // disparia nenhuma query nova — invalidando a comparação.
        Cache::flush();
        DB::enableQueryLog();
        $this->callHandler(['action' => 'getVisitorsByIp', 'per_page' => 50]);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallCount, $largeCount);
    }
}
