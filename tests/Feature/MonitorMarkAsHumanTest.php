<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Facades\Monitor as MonitorFacade;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 377 (v0.73.0): `Monitor::markAsHuman(?string $method =
 * null)` — marca o Monitor da sessão atual como humano depois que o host
 * app já validou alguma prova (Turnstile, checkbox, scroll test etc, fora
 * do escopo deste pacote). Ver README "Marking a visitor as human".
 *
 * Precedência `manual > auth > verified-* > ai` via
 * `MonitorLabel::isProtectedSource()` — mesma checagem usada por
 * `MonitorController::setMonitorKind` (ver `MonitorSetMonitorLabelTest`
 * pros testes HTTP do guard contra `source=ai`).
 */
class MonitorMarkAsHumanTest extends TestCase
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

    protected function monitorWithSession(array $data = []): Monitor
    {
        $monitor = Monitor::create(['data' => $data]);
        session(['monitor_id' => $monitor->id]);

        return $monitor;
    }

    // --- Preconditions (same contract as tag()/recognize()) ---

    public function test_returns_false_without_an_active_monitor_session(): void
    {
        $this->assertFalse(MonitorFacade::markAsHuman());
        $this->assertSame(0, MonitorLabel::count());
    }

    public function test_returns_false_when_the_session_monitor_id_does_not_exist(): void
    {
        session(['monitor_id' => 999]);

        $this->assertFalse(MonitorFacade::markAsHuman());
        $this->assertSame(0, MonitorLabel::count());
    }

    // --- Applies: no label, or over source=ai ---

    public function test_applies_when_the_monitor_has_no_label_yet(): void
    {
        $monitor = $this->monitorWithSession();

        $this->assertTrue(MonitorFacade::markAsHuman());

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('verified', $label->source);
        $this->assertNotNull($label->classified_at);
    }

    public function test_applies_without_a_method_argument_and_records_plain_verified_source(): void
    {
        $monitor = $this->monitorWithSession();

        $this->assertTrue(MonitorFacade::markAsHuman(null));

        $this->assertSame('verified', MonitorLabel::where('monitor_id', $monitor->id)->first()->source);
    }

    public function test_applies_over_an_ai_classified_bot(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'ai', 'classified_at' => now()->subDay()]);

        $this->assertTrue(MonitorFacade::markAsHuman('turnstile'));

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('verified-turnstile', $label->source);
    }

    public function test_applies_over_an_ai_classified_human_and_still_changes_the_source(): void
    {
        $monitor = $this->monitorWithSession();
        $originalDate = now()->subDay();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'ai', 'classified_at' => $originalDate]);

        $this->assertTrue(MonitorFacade::markAsHuman('turnstile'));

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('verified-turnstile', $label->source, 'kind já era human, mas source/classified_at ainda devem mudar');
        $this->assertTrue($label->classified_at->gt($originalDate));
    }

    public function test_applies_over_a_label_that_only_has_tags_and_no_kind_yet(): void
    {
        // laravel-monitor 377: uma linha só com tags (ex: addTag()) tem
        // kind=null mas source já carrega o default 'manual' do BANCO
        // (coluna nunca ficou sem default) - isso não deve ser confundido
        // com uma classificação manual de verdade.
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'tags' => ['registered']]);

        $this->assertSame('manual', MonitorLabel::where('monitor_id', $monitor->id)->first()->source, 'precondição: default do banco');

        $this->assertTrue(MonitorFacade::markAsHuman());

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('verified', $label->source);
        $this->assertSame(['registered'], $label->tags, 'tags existentes são preservadas');
    }

    // --- No-op: manual / auth / verified* ---

    public function test_is_a_silent_noop_over_a_manual_classification(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'bot', 'source' => 'manual', 'classified_at' => now()->subWeek()]);
        // Relido do banco (não a variável PHP em memória) pra comparar com
        // a MESMA precisão de timestamp que o cast `datetime` grava/lê -
        // evita falso negativo por microssegundos truncados no round-trip.
        $originalDate = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        $result = MonitorFacade::markAsHuman('turnstile');

        $this->assertFalse($result);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('bot', $label->kind);
        $this->assertSame('manual', $label->source);
        $this->assertTrue($label->classified_at->equalTo($originalDate), 'classified_at NUNCA muda num no-op');
    }

    public function test_is_a_silent_noop_over_an_auth_classification(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'auth', 'classified_at' => now()->subWeek()]);
        $originalDate = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        $result = MonitorFacade::markAsHuman('turnstile');

        $this->assertFalse($result);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
        $this->assertTrue($label->classified_at->equalTo($originalDate));
    }

    public function test_is_a_silent_noop_over_a_plain_verified_classification(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'verified', 'classified_at' => now()->subWeek()]);
        $originalDate = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        $result = MonitorFacade::markAsHuman('turnstile');

        $this->assertFalse($result);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('verified', $label->source);
        $this->assertTrue($label->classified_at->equalTo($originalDate));
    }

    public function test_is_a_silent_noop_over_a_verified_with_method_classification(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'verified-turnstile', 'classified_at' => now()->subWeek()]);
        $originalDate = MonitorLabel::where('monitor_id', $monitor->id)->first()->classified_at;

        $result = MonitorFacade::markAsHuman();

        $this->assertFalse($result);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('verified-turnstile', $label->source);
        $this->assertTrue($label->classified_at->equalTo($originalDate));
    }

    /**
     * Uma segunda chamada com um `$method` DIFERENTE é ignorada - o
     * método/data da primeira prova aceita sempre vence, nunca é
     * "atualizado" por uma prova posterior.
     */
    public function test_a_second_call_with_a_different_method_is_ignored(): void
    {
        $monitor = $this->monitorWithSession();

        $this->assertTrue(MonitorFacade::markAsHuman('turnstile'));
        $firstLabel = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $firstDate = $firstLabel->classified_at;
        $this->assertSame('verified-turnstile', $firstLabel->source);

        $result = MonitorFacade::markAsHuman('recaptcha');

        $this->assertFalse($result);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('verified-turnstile', $label->source, 'método da primeira prova continua valendo');
        $this->assertTrue($label->classified_at->equalTo($firstDate), 'data da primeira prova continua valendo');
    }

    // --- Normalization of $method ---

    public function test_method_is_slugified_lowercased_and_truncated_to_15_characters(): void
    {
        $monitor = $this->monitorWithSession();

        MonitorFacade::markAsHuman('Cloudflare Turnstile');

        // Str::slug('Cloudflare Turnstile') === 'cloudflare-turnstile' (20
        // chars) -> truncado a 15 -> 'cloudflare-turn'.
        $this->assertSame('verified-cloudflare-turn', MonitorLabel::where('monitor_id', $monitor->id)->first()->source);
    }

    public function test_method_with_invalid_characters_only_falls_back_to_plain_verified(): void
    {
        $monitor = $this->monitorWithSession();

        MonitorFacade::markAsHuman('!!!');

        $this->assertSame('verified', MonitorLabel::where('monitor_id', $monitor->id)->first()->source);
    }

    public function test_method_shorter_than_the_limit_is_kept_as_is(): void
    {
        $monitor = $this->monitorWithSession();

        MonitorFacade::markAsHuman('scroll');

        $this->assertSame('verified-scroll', MonitorLabel::where('monitor_id', $monitor->id)->first()->source);
    }

    // --- Interaction with the MonitorController guard (source=ai never overwrites verified*) ---

    /**
     * laravel-monitor 377: extensão do guard de `setMonitorKind` - uma
     * escrita `source=ai` subsequente (ex: a fila de triagem IA rodando
     * de novo por engano) NUNCA sobrescreve um `kind` gravado por
     * `markAsHuman()`. Testa o guard direto via `/monitor/handler`
     * (mesmo caminho HTTP real que `TriageMonitorIpsJob` do consumidor
     * usaria).
     */
    public function test_a_subsequent_ai_write_is_rejected_over_a_verified_label(): void
    {
        $monitor = $this->monitorWithSession();
        MonitorFacade::markAsHuman('turnstile');

        $response = $this->callHandler([
            'action' => 'setMonitorKind',
            'monitor_id' => $monitor->id,
            'kind' => 'bot',
            'source' => 'ai',
        ]);

        $response->assertOk();
        $response->assertJsonPath('applied', false);
        $response->assertJsonPath('kind', 'human');

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('verified-turnstile', $label->source);
    }

    /**
     * Mesmo guard, mas sobre `source=auth` (não só `verified*`) - cobre o
     * gap que existia antes desta task (`setMonitorKind` só checava
     * `=== 'manual'`).
     */
    public function test_a_subsequent_ai_write_is_rejected_over_an_auth_label(): void
    {
        $monitor = Monitor::create(['data' => []]);
        MonitorLabel::create(['monitor_id' => $monitor->id, 'kind' => 'human', 'source' => 'auth', 'classified_at' => now()]);

        $response = $this->callHandler([
            'action' => 'setMonitorKind',
            'monitor_id' => $monitor->id,
            'kind' => 'bot',
            'source' => 'ai',
        ]);

        $response->assertOk();
        $response->assertJsonPath('applied', false);

        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();
        $this->assertSame('human', $label->kind);
        $this->assertSame('auth', $label->source);
    }

    // --- MonitorLabel::isProtectedSource() directly ---

    public function test_is_protected_source_covers_manual_auth_and_any_verified_prefix(): void
    {
        $this->assertTrue(MonitorLabel::isProtectedSource('manual'));
        $this->assertTrue(MonitorLabel::isProtectedSource('auth'));
        $this->assertTrue(MonitorLabel::isProtectedSource('verified'));
        $this->assertTrue(MonitorLabel::isProtectedSource('verified-turnstile'));
        $this->assertTrue(MonitorLabel::isProtectedSource('verified-anything-else'));

        $this->assertFalse(MonitorLabel::isProtectedSource('ai'));
        $this->assertFalse(MonitorLabel::isProtectedSource(null));
        $this->assertFalse(MonitorLabel::isProtectedSource(''));
    }
}
