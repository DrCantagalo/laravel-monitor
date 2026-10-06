<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * laravel-monitor 258 (v0.53.0): novas read actions `getMonitorQueue`
 * (`group=unclassified`|`group=new` desde a 266/v0.55.0) e
 * `getMonitorQueueCounts` — substituem o filtro `clean_ai_queue` de
 * `getVisitorsByIp` (removido nesta mesma versão), agora listando
 * Monitors em vez de IPs. Ver README "IP classification".
 */
class MonitorQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * laravel-monitor 266 (v0.55.0): `unclassified` só inclui Monitor sem
     * `kind` mais velho que `ai_triage_min_age_hours` — um Monitor
     * recém-criado (como `Monitor::create()` sem `created_at` explícito)
     * cai em `new`, não em `unclassified`. Helper pra backdatar.
     * `created_at` não está em `$fillable` (de propósito, pra não deixar
     * o cliente forjar a idade de um Monitor via `data`), por isso usa
     * `forceFill()` em vez de passar pelo `create()`.
     */
    protected function oldMonitor(): Monitor
    {
        $monitor = Monitor::create(['data' => []]);
        $monitor->forceFill(['created_at' => now()->subDays(2)])->save();

        return $monitor;
    }

    public function test_unclassified_group_lists_monitors_without_any_kind_older_than_the_grace_period(): void
    {
        $unclassified = $this->oldMonitor();

        $classified = $this->oldMonitor();
        MonitorLabel::create(['monitor_id' => $classified->id, 'kind' => 'bot', 'source' => 'manual']);

        $tagOnly = $this->oldMonitor();
        MonitorLabel::create(['monitor_id' => $tagOnly->id, 'tags' => ['vpn']]);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unclassified->id, $ids);
        $this->assertContains($tagOnly->id, $ids);
        $this->assertNotContains($classified->id, $ids);
    }

    public function test_unclassified_group_excludes_monitors_seen_from_a_flagged_ip(): void
    {
        IpStat::create(['ip' => '1.1.1.1', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $monitor = $this->oldMonitor();
        $monitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $this->assertNotContains($monitor->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_unclassified_group_excludes_monitors_seen_from_a_blocked_ip(): void
    {
        BlockedIp::create(['ip' => '1.1.1.1']);

        $monitor = $this->oldMonitor();
        $monitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $this->assertNotContains($monitor->id, collect($response->json('data'))->pluck('id')->all());
    }

    /**
     * laravel-monitor 266 (v0.55.0): contraparte do grupo `unclassified`
     * — Monitor sem `kind` mais novo que o limiar de carência fica em
     * `new`, nunca em `unclassified`, e nunca entra em triagem.
     */
    public function test_new_group_lists_monitors_without_any_kind_within_the_grace_period(): void
    {
        $new = Monitor::create(['data' => []]);

        $old = $this->oldMonitor();

        $classified = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $classified->id, 'kind' => 'bot', 'source' => 'manual']);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'new']);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($new->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertNotContains($classified->id, $ids);
    }

    public function test_new_group_respects_the_configured_grace_period(): void
    {
        config(['monitor.ai_triage_min_age_hours' => 1]);

        $monitor = Monitor::create(['data' => []]);
        $monitor->forceFill(['created_at' => now()->subHours(2)])->save();

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'new']);
        $this->assertNotContains($monitor->id, collect($response->json('data'))->pluck('id')->all());

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);
        $this->assertContains($monitor->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_new_group_excludes_monitors_seen_from_a_flagged_ip(): void
    {
        IpStat::create(['ip' => '1.1.1.1', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $monitor = Monitor::create(['data' => []]);
        $monitor->recordIp('1.1.1.1');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'new']);

        $this->assertNotContains($monitor->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_invalid_group_returns_422(): void
    {
        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'bogus']);

        $response->assertStatus(422);
    }

    /**
     * laravel-monitor 266 (v0.55.0): `recheck` deixou de existir como
     * grupo válido — passar `group=recheck` agora é só mais um valor
     * inválido (422), igual qualquer outro fora de `MONITOR_QUEUE_GROUPS`.
     */
    public function test_recheck_group_no_longer_exists(): void
    {
        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'recheck']);

        $response->assertStatus(422);
    }

    public function test_counts_action_returns_both_numbers(): void
    {
        $this->oldMonitor();
        Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'getMonitorQueueCounts']);

        $response->assertOk();
        $response->assertJsonPath('unclassified', 1);
        $response->assertJsonPath('new', 1);
    }

    public function test_counts_action_excludes_classified_monitors_from_both_groups(): void
    {
        $classifiedOld = $this->oldMonitor();
        MonitorLabel::create(['monitor_id' => $classifiedOld->id, 'kind' => 'bot', 'source' => 'manual']);

        $classifiedNew = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $classifiedNew->id, 'kind' => 'human', 'source' => 'manual']);

        $response = $this->callHandler(['action' => 'getMonitorQueueCounts']);

        $response->assertOk();
        $response->assertJsonPath('unclassified', 0);
        $response->assertJsonPath('new', 0);
    }

    /**
     * laravel-monitor 295 (v0.61.0): quatro grupos novos reaproveitando
     * `getMonitorQueue`/`buildMonitorQueueResult` — pensados pra nova aba
     * "Monitors" do dashboard (consumo é a home-page 296, que vem depois).
     * `clean` = não visto em nenhum IP `flagged` nem bloqueado; `all` lista
     * tudo, sem exclusão, com `inherited_flagged` calculado por linha.
     */
    public function test_clean_group_excludes_monitors_seen_from_a_flagged_ip(): void
    {
        IpStat::create(['ip' => '1.1.1.1', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $flagged = Monitor::create(['data' => []]);
        $flagged->recordIp('1.1.1.1');

        $clean = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'clean']);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($clean->id, $ids);
        $this->assertNotContains($flagged->id, $ids);
    }

    public function test_clean_group_excludes_monitors_seen_from_a_blocked_ip(): void
    {
        BlockedIp::create(['ip' => '2.2.2.2']);

        $blocked = Monitor::create(['data' => []]);
        $blocked->recordIp('2.2.2.2');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'clean']);

        $this->assertNotContains($blocked->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_clean_group_includes_monitors_regardless_of_kind_or_age(): void
    {
        $classified = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $classified->id, 'kind' => 'bot', 'source' => 'manual']);

        $unclassified = $this->oldMonitor();

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'clean']);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($classified->id, $ids, 'clean não filtra por kind');
        $this->assertContains($unclassified->id, $ids, 'clean não filtra por idade');
    }

    public function test_clean_bots_and_clean_humans_split_by_kind_within_clean(): void
    {
        $bot = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $bot->id, 'kind' => 'bot', 'source' => 'manual']);

        $human = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $human->id, 'kind' => 'human', 'source' => 'manual']);

        $unclassified = Monitor::create(['data' => []]);

        $bots = collect($this->callHandler(['action' => 'getMonitorQueue', 'group' => 'clean_bots'])->json('data'))->pluck('id')->all();
        $humans = collect($this->callHandler(['action' => 'getMonitorQueue', 'group' => 'clean_humans'])->json('data'))->pluck('id')->all();

        $this->assertContains($bot->id, $bots);
        $this->assertNotContains($human->id, $bots);
        $this->assertNotContains($unclassified->id, $bots);

        $this->assertContains($human->id, $humans);
        $this->assertNotContains($bot->id, $humans);
        $this->assertNotContains($unclassified->id, $humans);
    }

    public function test_clean_bots_excludes_monitors_seen_from_a_flagged_ip(): void
    {
        IpStat::create(['ip' => '3.3.3.3', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $bot = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $bot->id, 'kind' => 'bot', 'source' => 'manual']);
        $bot->recordIp('3.3.3.3');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'clean_bots']);

        $this->assertNotContains($bot->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_all_group_lists_every_monitor_including_flagged_and_blocked(): void
    {
        IpStat::create(['ip' => '4.4.4.4', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $flagged = Monitor::create(['data' => []]);
        $flagged->recordIp('4.4.4.4');

        $clean = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all']);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($flagged->id, $ids);
        $this->assertContains($clean->id, $ids);
    }

    public function test_all_group_marks_inherited_flagged_for_monitors_seen_from_a_flagged_ip(): void
    {
        IpStat::create(['ip' => '5.5.5.5', 'visit_count' => 1, 'first_seen' => now(), 'last_seen' => now(), 'flagged' => true]);

        $flagged = Monitor::create(['data' => []]);
        $flagged->recordIp('5.5.5.5');

        $clean = Monitor::create(['data' => []]);

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all']);
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows[$flagged->id]['inherited_flagged']);
        $this->assertFalse($rows[$clean->id]['inherited_flagged']);
    }

    public function test_all_group_does_not_mark_inherited_flagged_for_a_merely_blocked_ip(): void
    {
        BlockedIp::create(['ip' => '6.6.6.6']);

        $blocked = Monitor::create(['data' => []]);
        $blocked->recordIp('6.6.6.6');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'all']);
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertFalse($rows[$blocked->id]['inherited_flagged']);
    }

    /**
     * laravel-monitor 299 (v0.61.1): `getMonitorQueueCounts()` cacheava
     * sob uma chave SEM nenhum componente de tempo, enquanto
     * `getMonitorQueue()` incluía `page`/`per_page`/`group` mas também
     * nada do cutoff — então um Monitor que envelhecia de `new` pra
     * `unclassified` só pela passagem do tempo (sem nenhuma escrita, que
     * é o único evento que bumpa `ListingsCache::invalidate()`) ficava
     * "congelado" no valor cacheado de cada método até o TTL
     * (`listings_cache_ttl_minutes`) expirar — e como os dois métodos
     * cacheiam de forma independente, eles podiam ficar congelados em
     * momentos diferentes, divergindo um do outro pro MESMO instante
     * lógico. Este teste prova a divergência seria reproduzível sem o
     * fix: avança o relógio de teste só 2 minutos (bem dentro do TTL
     * default de 5min) depois de popular os dois caches, o suficiente pra
     * cruzar o cutoff de `ai_triage_min_age_hours` e mover o Monitor de
     * `new` pra `unclassified` — e confirma que AMBOS os métodos já
     * refletem a mudança e continuam concordando entre si (fix:
     * `aiTriageCutoffBucket()` na chave de cache dos dois).
     */
    public function test_queue_counts_never_diverge_from_the_queue_listing_when_a_monitor_crosses_the_ai_triage_cutoff(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 12, 0, 0, 'UTC'));

        config(['monitor.ai_triage_min_age_hours' => 24]);

        $monitor = Monitor::create(['data' => []]);
        $monitor->forceFill(['created_at' => now()->subHours(24)->addMinute()])->save();

        // Popula os dois caches enquanto o Monitor ainda é `new`.
        $countsBefore = $this->callHandler(['action' => 'getMonitorQueueCounts']);
        $countsBefore->assertJsonPath('new', 1);
        $countsBefore->assertJsonPath('unclassified', 0);

        $queueBefore = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);
        $this->assertNotContains($monitor->id, collect($queueBefore->json('data'))->pluck('id')->all());

        // O relógio avança 2min (< TTL de 5min) — o cutoff também avança
        // 2min, e o Monitor (criado 1min depois do cutoff ORIGINAL)
        // agora fica 1min ANTES do cutoff novo: cruzou pra `unclassified`
        // só pela passagem do tempo, sem nenhuma escrita.
        Carbon::setTestNow(now()->addMinutes(2));

        $countsAfter = $this->callHandler(['action' => 'getMonitorQueueCounts']);
        $queueAfter = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified']);
        $queueAfterIds = collect($queueAfter->json('data'))->pluck('id')->all();

        $countsAfter->assertJsonPath('new', 0);
        $countsAfter->assertJsonPath('unclassified', 1);
        $this->assertContains($monitor->id, $queueAfterIds, 'getMonitorQueue deveria já refletir o Monitor como unclassified');

        // As duas respostas pro mesmo instante lógico nunca podem
        // divergir: o count de `unclassified` tem que bater com o
        // tamanho da listagem do grupo `unclassified`.
        $this->assertSame($countsAfter->json('unclassified'), count($queueAfterIds));
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'getMonitorQueue', 'group' => 'unclassified']);

        $response->assertStatus(401);
    }

    public function test_accepts_ephemeral_read_token(): void
    {
        config(['monitor.local_token' => 'test-local-token']);
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'getMonitorQueue', 'group' => 'unclassified'], $token);

        $response->assertOk();
    }
}
