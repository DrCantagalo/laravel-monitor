<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorAccessLog;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Models\MonitorVisit;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 280 (v0.58.0): novo read action `getTimeline` — séries
 * diárias (visitantes novos por classificação, visitas clean/scraper,
 * acessos aos dados) pros gráficos "Overview" do dashboard. Ver
 * `MonitorController::getTimeline()`/`buildTimelineResult()`.
 */
class MonitorTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * `created_at` não é `$fillable` em nenhum destes models — `forceFill`
     * (mesmo padrão de `MonitorGetDataTest::monitorAgedHours()`).
     */
    protected function monitorAt(string $createdAt, ?string $kind = null): Monitor
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->forceFill(['created_at' => $createdAt])->save();

        if ($kind !== null) {
            MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => $kind]);
        }

        return $monitor;
    }

    protected function visitAt(string $createdAt, bool $scraper = false): MonitorVisit
    {
        $monitor = Monitor::create(['data' => []]);
        $visit = MonitorVisit::create(['monitor_id' => $monitor->id, 'paths' => ['/'], 'scraper' => $scraper]);
        $visit->forceFill(['created_at' => $createdAt])->save();

        return $visit;
    }

    protected function accessLogAt(string $accessedAt, string $kind): MonitorAccessLog
    {
        return MonitorAccessLog::create([
            'accessed_at' => $accessedAt,
            'kind' => $kind,
            'action' => 'getData',
            'ip' => '203.0.113.5',
        ]);
    }

    public function test_default_days_is_30_and_days_array_matches_series_length(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $response = $this->callHandler(['action' => 'getTimeline']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertCount(30, $response->json('days'));
        $this->assertSame('2026-09-03', $response->json('days.0'));
        $this->assertSame('2026-10-02', $response->json('days.29'));
        $this->assertCount(30, $response->json('series.monitors_new.human'));
        $this->assertCount(30, $response->json('series.visits.clean'));
        $this->assertCount(30, $response->json('series.access.local_token_read'));
    }

    public function test_rejects_non_integer_days(): void
    {
        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 'abc']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_rejects_days_out_of_range(): void
    {
        $this->callHandler(['action' => 'getTimeline', 'days' => 6])->assertStatus(422);
        $this->callHandler(['action' => 'getTimeline', 'days' => 366])->assertStatus(422);
        $this->callHandler(['action' => 'getTimeline', 'days' => 7])->assertOk();
        $this->callHandler(['action' => 'getTimeline', 'days' => 365])->assertOk();
    }

    public function test_days_without_data_are_filled_with_zero(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $this->monitorAt('2026-10-02 08:00:00', 'human');

        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);

        $response->assertOk();
        $series = $response->json('series.monitors_new.human');
        $this->assertSame([0, 0, 0, 0, 0, 0, 1], $series);
    }

    public function test_splits_monitors_new_by_human_bot_and_unclassified(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $this->monitorAt('2026-10-02 08:00:00', 'human');
        $this->monitorAt('2026-10-02 09:00:00', 'bot');
        $this->monitorAt('2026-10-02 10:00:00');

        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);

        $response->assertOk();
        $this->assertSame(1, $response->json('series.monitors_new.human.6'));
        $this->assertSame(1, $response->json('series.monitors_new.bot.6'));
        $this->assertSame(1, $response->json('series.monitors_new.unclassified.6'));
    }

    /**
     * Sem `monitor_labels` (instalação não migrada), todo Monitor conta
     * como sem `kind` — mesmo fail-open de `monitorsByKind()` em getData.
     */
    public function test_falls_back_to_unclassified_when_monitor_labels_table_is_missing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $this->monitorAt('2026-10-02 08:00:00', 'human');
        Schema::drop('monitor_labels');

        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);

        $response->assertOk();
        $this->assertSame(0, $response->json('series.monitors_new.human.6'));
        $this->assertSame(1, $response->json('series.monitors_new.unclassified.6'));
    }

    public function test_splits_visits_by_clean_and_scraper(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $this->visitAt('2026-10-02 08:00:00', false);
        $this->visitAt('2026-10-02 09:00:00', true);

        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);

        $response->assertOk();
        $this->assertSame(1, $response->json('series.visits.clean.6'));
        $this->assertSame(1, $response->json('series.visits.scraper.6'));
    }

    public function test_splits_access_by_kind_and_excludes_get_timeline_itself_from_its_own_window_count(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $this->accessLogAt('2026-10-02 08:00:00', 'read_token_issued');
        $this->accessLogAt('2026-10-02 08:05:00', 'read_token_first_use');
        $this->accessLogAt('2026-10-02 08:10:00', 'local_token_read');

        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);

        $response->assertOk();
        // getTimeline está em ACCESS_LOGGED_ACTIONS: a própria chamada
        // acima (local_token) grava mais 1 local_token_read, gerado DEPOIS
        // do corte horário fixado por Carbon::setTestNow — ainda cai no
        // mesmo dia (índice 6), então soma 2, não 1.
        $this->assertSame(1, $response->json('series.access.read_token_issued.6'));
        $this->assertSame(1, $response->json('series.access.read_token_first_use.6'));
        $this->assertSame(2, $response->json('series.access.local_token_read.6'));
    }

    public function test_tables_missing_fail_open_with_zeroed_series_instead_of_500(): void
    {
        Schema::drop('monitors');
        Schema::drop('monitor_visits');
        Schema::drop('monitor_access_logs');

        $response = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);

        $response->assertOk();
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $response->json('series.monitors_new.human'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $response->json('series.visits.clean'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $response->json('series.access.local_token_read'));
    }

    public function test_timezone_in_response_reflects_app_config_and_shifts_which_day_is_today(): void
    {
        // Instante fixo: 2026-10-02 02:00 UTC == 2026-10-01 23:00
        // America/Sao_Paulo (UTC-3) — "hoje" muda de dia dependendo do
        // timezone do app, mesmo instante real. `days` diferente em cada
        // chamada só pra não colidir na mesma chave de cache (que não
        // inclui o timezone — `app.timezone` não muda em runtime numa
        // installation real, só aqui no teste).
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 2, 0, 0, 'UTC'));

        config(['app.timezone' => 'UTC']);
        $utcResponse = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);
        $utcResponse->assertOk();
        $this->assertSame('UTC', $utcResponse->json('timezone'));
        $this->assertSame('2026-10-02', $utcResponse->json('days.6'));

        config(['app.timezone' => 'America/Sao_Paulo']);
        $spResponse = $this->callHandler(['action' => 'getTimeline', 'days' => 9]);
        $spResponse->assertOk();
        $this->assertSame('America/Sao_Paulo', $spResponse->json('timezone'));
        $this->assertSame('2026-10-01', $spResponse->json('days.8'));
    }

    public function test_result_is_cached_per_days_value(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 2, 12, 0, 0, 'UTC'));

        $this->monitorAt('2026-10-02 08:00:00', 'human');

        $first = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);
        $this->assertSame(1, $first->json('series.monitors_new.human.6'));

        // Segunda chamada com os MESMOS `days`: escrita direta no banco
        // não deveria refletir (cache).
        $this->monitorAt('2026-10-02 09:00:00', 'human');
        $second = $this->callHandler(['action' => 'getTimeline', 'days' => 7]);
        $this->assertSame(1, $second->json('series.monitors_new.human.6'), 'segunda chamada deveria vir do cache');

        // `days` diferente: chave de cache diferente, sem cache ainda —
        // reflete o estado atual (2 monitors humanos).
        $third = $this->callHandler(['action' => 'getTimeline', 'days' => 30]);
        $this->assertSame(2, $third->json('series.monitors_new.human.29'));
    }

    public function test_accepts_ephemeral_read_token(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'getTimeline'], $token);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'getTimeline']);

        $response->assertStatus(401);
    }
}
