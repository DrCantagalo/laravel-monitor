<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Models\MonitorVisit;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 308 (v0.63.0): `clearData` ganha `older_than_days`/
 * `categories[]` opcionais, unificando o antigo `clearData` (truncate
 * total) com o antigo `pruneData` (cleanup parcial por idade) — a
 * categoria usa a MESMA regra de `MonitorCategories`/`categoryForMonitor()`/
 * `aggregateMonitorsByKind()` (`getData.monitors_by_kind`). `pruneData`
 * continua aceito como alias depreciado (resposta no shape antigo). Novo
 * read-only `previewClearData` (mesmos parâmetros, só conta).
 */
class MonitorClearDataCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function monitor(?string $kind = null, ?int $userId = null): Monitor
    {
        $monitor = Monitor::create(['data' => $userId !== null ? ['user_id' => $userId] : []]);

        if ($kind !== null) {
            MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => $kind]);
        }

        return $monitor;
    }

    /**
     * Mesmo critério de `MonitorCategories::flaggedMonitorIds()`: visto em
     * pelo menos um IP com `monitor_ip_stats.flagged=true`.
     */
    protected function flagMonitorIp(Monitor $monitor, string $ip): void
    {
        IpStat::create(['ip' => $ip, 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);
        $monitor->recordIp($ip);
    }

    /**
     * `updated_at` mais antigo que `$days` dias — mesma coluna que
     * `DataPruner::pruneMonitors()` usa pra "antigo" (nunca `created_at`).
     */
    protected function ageMonitor(Monitor $monitor, int $days): void
    {
        $monitor->timestamps = false;
        $monitor->updated_at = now()->subDays($days);
        $monitor->save();
    }

    protected function ageVisit(MonitorVisit $visit, int $days): void
    {
        $visit->timestamps = false;
        $visit->updated_at = now()->subDays($days);
        $visit->save();
    }

    // --- no parameters at all: exact old clearData behavior -----------

    public function test_no_params_deletes_every_monitor_and_never_touches_ip_stats(): void
    {
        $this->monitor('bot');
        $this->monitor('human');
        $old = $this->monitor();
        $this->ageMonitor($old, 365);

        IpStat::create(['ip' => '203.0.113.1', 'visit_count' => 1, 'first_seen' => now()->subDays(365), 'last_seen' => now()->subDays(365), 'flagged' => false]);

        $response = $this->callHandler(['action' => 'clearData']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('monitors_deleted', 3);
        $response->assertJsonPath('ip_stats_deleted', 0);
        $response->assertJsonPath('visits_deleted', 0);
        $response->assertJsonPath('page_hits_deleted', 0);

        $this->assertSame(0, Monitor::count());
        $this->assertSame(1, IpStat::count(), 'clearData sem parametro nenhum nunca tocou monitor_ip_stats');
    }

    // --- each category in isolation ------------------------------------

    public function test_categories_bot_deletes_only_bots(): void
    {
        $bot = $this->monitor('bot');
        $human = $this->monitor('human');

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['bot']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($bot->id));
        $this->assertNotNull(Monitor::find($human->id));
    }

    public function test_categories_human_user_deletes_only_authenticated_humans(): void
    {
        $guest = $this->monitor('human');
        $user = $this->monitor('human', userId: 42);

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['human_user']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($user->id));
        $this->assertNotNull(Monitor::find($guest->id));
    }

    public function test_categories_human_guest_deletes_only_unauthenticated_humans(): void
    {
        $guest = $this->monitor('human');
        $user = $this->monitor('human', userId: 42);

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['human_guest']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($guest->id));
        $this->assertNotNull(Monitor::find($user->id));
    }

    public function test_categories_flagged_deletes_only_flagged_unclassified_monitors(): void
    {
        $flagged = $this->monitor();
        $this->flagMonitorIp($flagged, '198.51.100.1');
        $clean = $this->monitor();

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['flagged']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($flagged->id));
        $this->assertNotNull(Monitor::find($clean->id));
    }

    public function test_categories_unclassified_deletes_only_clean_unclassified_monitors(): void
    {
        $flagged = $this->monitor();
        $this->flagMonitorIp($flagged, '198.51.100.2');
        $clean = $this->monitor();

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['unclassified']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($clean->id));
        $this->assertNotNull(Monitor::find($flagged->id));
    }

    /**
     * Decisão de produto deliberada da task 308: `unclassified` cobre
     * TAMBÉM o bucket `new` de `monitors_by_kind` (Monitor sem `kind`, não
     * flagado, mais novo que `aiTriageCutoff()`) — nunca excluído daqui,
     * mesmo sendo um grupo à parte só dentro daquele agregado específico.
     */
    public function test_unclassified_category_also_matches_a_brand_new_monitor(): void
    {
        config(['monitor.ai_triage_min_age_hours' => 24]);

        $new = $this->monitor(); // created_at = now(), bem dentro da carência

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['unclassified']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($new->id));
    }

    // --- combination of categories --------------------------------------

    public function test_combination_of_categories_deletes_the_union(): void
    {
        $bot = $this->monitor('bot');
        $flagged = $this->monitor();
        $this->flagMonitorIp($flagged, '198.51.100.3');
        $guest = $this->monitor('human');
        $user = $this->monitor('human', userId: 7);
        $clean = $this->monitor();

        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['bot', 'flagged']]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 2);
        $this->assertNull(Monitor::find($bot->id));
        $this->assertNull(Monitor::find($flagged->id));
        $this->assertNotNull(Monitor::find($guest->id));
        $this->assertNotNull(Monitor::find($user->id));
        $this->assertNotNull(Monitor::find($clean->id));
    }

    // --- age + category combined ----------------------------------------

    public function test_age_and_category_combined_only_deletes_old_matching_rows(): void
    {
        $oldClean = $this->monitor();
        $this->ageMonitor($oldClean, 30);

        $newClean = $this->monitor(); // mesma categoria, mas recente

        $oldBot = $this->monitor('bot');
        $this->ageMonitor($oldBot, 30);

        $response = $this->callHandler([
            'action' => 'clearData',
            'categories' => ['unclassified'],
            'older_than_days' => 10,
        ]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $this->assertNull(Monitor::find($oldClean->id));
        $this->assertNotNull(Monitor::find($newClean->id), 'categoria bate mas e novo demais, nao deveria apagar');
        $this->assertNotNull(Monitor::find($oldBot->id), 'idade bate mas categoria nao, nao deveria apagar');
    }

    // --- monitor_ip_stats untouched with a partial category filter ------

    public function test_ip_stats_untouched_when_category_filter_is_partial_even_with_matching_age(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 60);

        $stat = IpStat::create(['ip' => '198.51.100.4', 'visit_count' => 1, 'first_seen' => now()->subDays(60), 'last_seen' => now()->subDays(60), 'flagged' => false]);

        $response = $this->callHandler([
            'action' => 'clearData',
            'categories' => ['bot'],
            'older_than_days' => 10,
        ]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 1);
        $response->assertJsonPath('ip_stats_deleted', 0);
        $this->assertNotNull(IpStat::find($stat->id), 'monitor_ip_stats e por IP, nao por categoria/Monitor - nunca deveria ser tocado com filtro parcial');
        $this->assertSame(1, IpStat::count());
    }

    public function test_ip_stats_is_pruned_when_every_category_is_explicitly_selected(): void
    {
        $old = $this->monitor();
        $this->ageMonitor($old, 60);

        IpStat::create(['ip' => '198.51.100.5', 'visit_count' => 1, 'first_seen' => now()->subDays(60), 'last_seen' => now()->subDays(60), 'flagged' => false]);

        $response = $this->callHandler([
            'action' => 'clearData',
            'categories' => ['human_user', 'human_guest', 'bot', 'flagged', 'unclassified'],
            'older_than_days' => 10,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ip_stats_deleted', 1);
        $this->assertSame(0, IpStat::count());
    }

    // --- previewClearData matches what clearData actually deletes ------

    public function test_preview_clear_data_count_matches_actual_delete_for_partial_categories(): void
    {
        $this->monitor('bot');
        $this->monitor('bot');
        $flagged = $this->monitor();
        $this->flagMonitorIp($flagged, '198.51.100.6');
        $this->monitor('human');

        $params = ['action' => 'previewClearData', 'categories' => ['bot', 'flagged']];
        $preview = $this->callHandler($params);
        $preview->assertOk();
        $preview->assertJsonPath('monitors_deleted', 3);
        $preview->assertJsonPath('ip_stats_deleted', 0);

        // Preview nao apaga nada.
        $this->assertSame(4, Monitor::count());

        $clear = $this->callHandler(['action' => 'clearData', 'categories' => ['bot', 'flagged']]);
        $clear->assertOk();
        $clear->assertJsonPath('monitors_deleted', $preview->json('monitors_deleted'));
    }

    public function test_preview_clear_data_count_matches_actual_delete_for_all_categories_with_cutoff(): void
    {
        config(['monitor.visits_retention_days' => 10]);

        $old = $this->monitor();
        $this->ageMonitor($old, 20);
        IpStat::create(['ip' => '198.51.100.7', 'visit_count' => 1, 'first_seen' => now()->subDays(20), 'last_seen' => now()->subDays(20), 'flagged' => false]);

        // Visitas de um Monitor DIFERENTE, recente (nunca apagado pelo
        // corte de idade de 3 dias) — de propósito, pra isolar a contagem
        // de monitor_visits do cascade da FK: se as visitas pertencessem
        // ao MESMO Monitor ($old, que o corte de monitors ja apaga), elas
        // já teriam sumido via CASCADE antes do delete explicito de
        // monitor_visits rodar, e visits_deleted ficaria menor que o
        // preview só por essa sobreposição - não é o que este teste quer
        // verificar.
        $visitOwner = $this->monitor();

        // visita que bate nos DOIS cutoffs (retention=10d e older_than_days=3)
        // - naive soma das duas contagens separadas daria 2 (double count),
        // o delete de verdade so apaga 1 linha (uniao, nunca soma).
        $bothVisit = MonitorVisit::create(['monitor_id' => $visitOwner->id, 'paths' => ['/'], 'scraper' => false]);
        $this->ageVisit($bothVisit, 15);

        // visita que bate so no cutoff explicito (5 dias: >3 mas <10).
        $cutoffOnlyVisit = MonitorVisit::create(['monitor_id' => $visitOwner->id, 'paths' => ['/'], 'scraper' => false]);
        $this->ageVisit($cutoffOnlyVisit, 5);

        // visita recente demais pros dois cutoffs - nunca deveria sumir.
        $recentVisit = MonitorVisit::create(['monitor_id' => $visitOwner->id, 'paths' => ['/'], 'scraper' => false]);
        $this->ageVisit($recentVisit, 1);

        $params = ['action' => 'previewClearData', 'older_than_days' => 3];
        $preview = $this->callHandler($params);
        $preview->assertOk();
        $preview->assertJsonPath('monitors_deleted', 1);
        $preview->assertJsonPath('ip_stats_deleted', 1);
        $preview->assertJsonPath('visits_deleted', 2);

        $this->assertSame(2, Monitor::count(), 'preview nao deveria ter apagado nada');

        $clear = $this->callHandler(['action' => 'clearData', 'older_than_days' => 3]);
        $clear->assertOk();
        $clear->assertJsonPath('monitors_deleted', 1);
        $clear->assertJsonPath('ip_stats_deleted', 1);
        $clear->assertJsonPath('visits_deleted', 2);

        $this->assertNull(MonitorVisit::find($bothVisit->id));
        $this->assertNull(MonitorVisit::find($cutoffOnlyVisit->id));
        $this->assertNotNull(MonitorVisit::find($recentVisit->id));
    }

    // --- deprecated pruneData alias still works, old response shape ----

    public function test_prune_data_alias_still_requires_older_than_days_and_matches_old_response_shape(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);
        $human = $this->monitor('human');
        $this->ageMonitor($human, 10);

        $response = $this->callHandler(['action' => 'pruneData', 'older_than_days' => 1]);

        $response->assertOk();
        $response->assertExactJson([
            'success' => true,
            'monitors_deleted' => 2,
            'ip_stats_deleted' => 0,
            'visits_deleted' => 0,
            'page_hits_deleted' => 0,
        ]);

        $this->assertSame(0, Monitor::count(), 'pruneData continua apagando TODAS as categorias, igual sempre foi');
    }

    public function test_prune_data_ignores_a_categories_param_if_an_updated_client_sends_one(): void
    {
        $bot = $this->monitor('bot');
        $this->ageMonitor($bot, 10);
        $human = $this->monitor('human');
        $this->ageMonitor($human, 10);

        // Um dashboard ja atualizado nao deveria mandar 'categories' pra
        // pruneData (ele nao tem esse conceito), mas se mandar, tem que
        // ser ignorado silenciosamente - pruneData sempre varre TODAS as
        // categorias, exatamente como sempre fez.
        $response = $this->callHandler([
            'action' => 'pruneData',
            'older_than_days' => 1,
            'categories' => ['bot'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 2);
        $this->assertSame(0, Monitor::count());
    }

    public function test_prune_data_still_422s_without_older_than_days(): void
    {
        $response = $this->callHandler(['action' => 'pruneData']);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // --- validation ------------------------------------------------------

    public function test_clear_data_422s_on_negative_older_than_days(): void
    {
        $response = $this->callHandler(['action' => 'clearData', 'older_than_days' => -1]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_clear_data_422s_on_invalid_category(): void
    {
        $response = $this->callHandler(['action' => 'clearData', 'categories' => ['not_a_real_category']]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_clear_data_422s_on_empty_categories_array(): void
    {
        $response = $this->callHandler(['action' => 'clearData', 'categories' => []]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_preview_clear_data_also_validates_the_same_way(): void
    {
        $response = $this->callHandler(['action' => 'previewClearData', 'categories' => ['nope']]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_preview_clear_data_never_deletes_anything(): void
    {
        $this->monitor('bot');
        $this->monitor('human');

        $response = $this->callHandler(['action' => 'previewClearData']);

        $response->assertOk();
        $response->assertJsonPath('monitors_deleted', 2);
        $this->assertSame(2, Monitor::count());
    }

    // --- monitor:data:monitors-by-kind cache invalidation ---------------

    public function test_clear_data_invalidates_the_monitors_by_kind_cache(): void
    {
        $bot = $this->monitor('bot');
        $this->monitor('human');

        $first = $this->callHandler(['action' => 'getData']);
        $first->assertOk();
        $this->assertSame(1, $first->json('monitors_by_kind.bot'));

        $this->callHandler(['action' => 'clearData', 'categories' => ['bot']]);

        $second = $this->callHandler(['action' => 'getData']);
        $second->assertOk();
        $this->assertSame(
            0,
            $second->json('monitors_by_kind.bot'),
            'monitor:data:monitors-by-kind deveria ter sido invalidado, nao esperar o TTL de 45s'
        );
    }
}
