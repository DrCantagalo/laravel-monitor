<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Http\Middleware\MonitorApiMethod;
use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorApiBlockEvent;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Drcantagalo\LaravelMonitor\Models\MonitorVisit;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\ApiTokenVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\ScraperSignalDetector;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * laravel-monitor 400: `MonitorApiMethod`, registrada pelo próprio pacote
 * no grupo `api` (`MonitorServiceProvider::boot()`, mesmo esquema de
 * `MonitorMethod` em `web`). Cobre: grouping por token Sanctum (um
 * Monitor por `personal_access_tokens.id`, NUNCA por usuário), fallback
 * por IP sem token, boundary de "visita" por tempo
 * (`api_visit_gap_minutes`), kind/source/tags do Monitor de token,
 * IP bloqueado com token válido (403 JSON + `monitor_api_block_events`),
 * token inválido contado como sinal de scraper, e degradação graciosa
 * sem `laravel/sanctum` instalado.
 *
 * `laravel/sanctum` é dependência opcional do pacote em si (ver README
 * "Sanctum (optional)") mas é `require-dev` DESTE pacote, só pros testes
 * — a tabela `personal_access_tokens` não é migrada automaticamente
 * (Sanctum só publica a migration, nunca a auto-carrega, diferente do
 * `loadMigrationsFrom` que este pacote usa pras próprias), então
 * `setUp()` recria o schema dela manualmente (mesma definição da
 * migration oficial do Sanctum).
 */
class MonitorApiMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `Application::runningInConsole()` decide pelo SAPI do PHP
        // (`PHP_SAPI === 'cli'`), cacheado na primeira chamada — sob
        // `phpunit` (sempre CLI) isso é `true` mesmo numa chamada HTTP
        // simulada via `$this->getJson()`, o que faria
        // `MonitorApiMethod::handle()` (mesmo guard de `MonitorMethod`)
        // pular TODO o enforcement/tracking sem nenhuma pista no teste
        // (seria um falso "passou" sem nunca ter exercitado o middleware
        // de verdade). Força via Reflection pra este teste simular uma
        // request HTTP de produção (SAPI != cli) — nenhum outro teste do
        // pacote precisou disso até agora porque nenhum outro exercita o
        // middleware por uma request HTTP real (todos chamam os trackers
        // direto, pulando o Kernel).
        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue($this->app, false);

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function ($table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    protected function defineRoutes($router)
    {
        $router->middleware('api')->get('/__test/api-ping', fn () => response()->json(['ok' => true]));
        $router->middleware('api')->get('/__test/api-404', fn () => response()->json(['ok' => false], 404));
    }

    /**
     * Cria um token Sanctum "cru" (sem precisar de um model tokenable de
     * verdade — `MonitorApiMethod`/`ApiTokenVisitorTracker` só leem
     * `tokenable_id` como atributo bruto, nunca resolvem a relação
     * `tokenable()`) e devolve o plain-text token (`"{id}|{secret}"`, o
     * mesmo formato que `PersonalAccessToken::findToken()` espera) junto
     * com o model já persistido.
     */
    protected function createToken(array $attrs = []): array
    {
        $secret = Str::random(40);

        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => $attrs['tokenable_type'] ?? 'App\\Models\\User',
            'tokenable_id' => $attrs['tokenable_id'] ?? 99,
            'name' => $attrs['name'] ?? 'test-token',
            'token' => hash('sha256', $secret),
            'abilities' => json_encode(['*']),
            'expires_at' => $attrs['expires_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'plain' => "{$id}|{$secret}",
            'model' => PersonalAccessToken::find($id),
        ];
    }

    protected function callApi(string $uri, ?string $bearerToken = null, string $ip = '203.0.113.9')
    {
        $headers = [];

        if ($bearerToken !== null) {
            $headers['Authorization'] = 'Bearer '.$bearerToken;
        }

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson($uri, $headers);
    }

    // --- Grouping: token vs IP fallback ---

    public function test_a_valid_token_creates_a_monitor_grouped_by_token_id(): void
    {
        $token = $this->createToken(['tokenable_id' => 42]);

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $this->assertSame(1, Monitor::count());
        $monitor = Monitor::first();
        $this->assertSame($token['model']->id, $monitor->token_id);
        $this->assertSame(42, $monitor->data['user_id']);
    }

    public function test_repeated_calls_with_the_same_token_reuse_the_same_monitor(): void
    {
        $token = $this->createToken();

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();
        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();
        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $this->assertSame(1, Monitor::count());
    }

    /**
     * Item 2 da task: grouping é por TOKEN, não por usuário — dois tokens
     * do mesmo `tokenable_id` são dois Monitors distintos.
     */
    public function test_two_different_tokens_of_the_same_user_create_two_monitors(): void
    {
        $tokenA = $this->createToken(['tokenable_id' => 7, 'name' => 'mobile-app']);
        $tokenB = $this->createToken(['tokenable_id' => 7, 'name' => 'integration-script']);

        $this->callApi('/__test/api-ping', $tokenA['plain'])->assertOk();
        $this->callApi('/__test/api-ping', $tokenB['plain'])->assertOk();

        $this->assertSame(2, Monitor::count());
        $this->assertEqualsCanonicalizing(
            [$tokenA['model']->id, $tokenB['model']->id],
            Monitor::pluck('token_id')->all()
        );
    }

    public function test_no_token_at_all_falls_back_to_ip_based_grouping(): void
    {
        $this->callApi('/__test/api-ping', null, '198.51.100.20')->assertOk();
        $this->callApi('/__test/api-ping', null, '198.51.100.20')->assertOk();

        $this->assertSame(1, Monitor::count());
        $monitor = Monitor::first();
        $this->assertNull($monitor->token_id);

        $this->assertSame(1, DB::table('monitor_visit_ips')
            ->where('monitor_id', $monitor->id)->where('ip', '198.51.100.20')->count());
    }

    public function test_different_ips_without_a_token_create_different_monitors(): void
    {
        $this->callApi('/__test/api-ping', null, '198.51.100.21')->assertOk();
        $this->callApi('/__test/api-ping', null, '198.51.100.22')->assertOk();

        $this->assertSame(2, Monitor::count());
    }

    // --- Kind/source/tags of a token Monitor ---

    public function test_token_monitor_is_classified_bot_source_auth_with_user_and_api_tags(): void
    {
        $token = $this->createToken();

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $monitor = Monitor::first();
        $label = MonitorLabel::where('monitor_id', $monitor->id)->first();

        $this->assertNotNull($label);
        $this->assertSame('bot', $label->kind);
        $this->assertSame('auth', $label->source);
        $this->assertEqualsCanonicalizing(['user', 'api'], $label->tags);
    }

    /**
     * `source=auth` já é protegido por `MonitorLabel::isProtectedSource()`
     * — uma escrita `source=ai` subsequente (ex: a fila de triagem IA)
     * não pode sobrescrever. Não é um comportamento NOVO desta task (a
     * proteção já existia pra `source=auth` da SessionVisitorTracker),
     * mas vale confirmar que o Monitor de token entra nessa mesma regra.
     */
    public function test_token_monitor_classification_is_protected_against_ai_overwrite(): void
    {
        $token = $this->createToken();
        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $monitor = Monitor::first();

        $this->assertTrue(MonitorLabel::isProtectedSource(
            MonitorLabel::where('monitor_id', $monitor->id)->value('source')
        ));
    }

    // --- Visit boundary (api_visit_gap_minutes) ---

    public function test_calls_within_the_gap_append_to_the_same_visit(): void
    {
        config(['monitor.api_visit_gap_minutes' => 30]);
        $token = $this->createToken();

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();
        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $monitor = Monitor::first();
        $this->assertSame(1, MonitorVisit::where('monitor_id', $monitor->id)->count());

        $visit = MonitorVisit::where('monitor_id', $monitor->id)->first();
        $this->assertCount(2, $visit->paths);
    }

    public function test_a_call_after_the_gap_opens_a_new_visit(): void
    {
        config(['monitor.api_visit_gap_minutes' => 30]);
        $token = $this->createToken();

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();
        $monitor = Monitor::first();

        MonitorVisit::where('monitor_id', $monitor->id)->update([
            'updated_at' => now()->subMinutes(31),
        ]);

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $this->assertSame(2, MonitorVisit::where('monitor_id', $monitor->id)->count());
    }

    public function test_a_call_inside_the_gap_boundary_does_not_open_a_new_visit(): void
    {
        config(['monitor.api_visit_gap_minutes' => 30]);
        $token = $this->createToken();

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();
        $monitor = Monitor::first();

        MonitorVisit::where('monitor_id', $monitor->id)->update([
            'updated_at' => now()->subMinutes(29),
        ]);

        $this->callApi('/__test/api-ping', $token['plain'])->assertOk();

        $this->assertSame(1, MonitorVisit::where('monitor_id', $monitor->id)->count());
    }

    // --- Blocked IP with a valid token (item 5) ---

    public function test_blocked_ip_with_a_valid_token_returns_json_403_and_records_the_event(): void
    {
        BlockedIp::create(['ip' => '203.0.113.50', 'source' => 'manual']);
        $token = $this->createToken(['tokenable_id' => 55, 'name' => 'mobile-app']);

        $response = $this->callApi('/__test/api-ping', $token['plain'], '203.0.113.50');

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $this->assertSame('application/json', $response->headers->get('content-type') ? strtok($response->headers->get('content-type'), ';') : null);

        $event = MonitorApiBlockEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('203.0.113.50', $event->ip);
        $this->assertSame($token['model']->id, $event->token_id);
        $this->assertSame('mobile-app', $event->token_name);
        $this->assertSame('55', (string) $event->user_id);
        $this->assertSame('request bloqueada com token válido', $event->message);

        // Bloqueado -> $next() nunca roda -> nenhum Monitor é criado.
        $this->assertSame(0, Monitor::count());
    }

    public function test_blocked_ip_without_a_token_returns_json_403_without_recording_a_valid_token_event(): void
    {
        BlockedIp::create(['ip' => '203.0.113.51', 'source' => 'manual']);

        $response = $this->callApi('/__test/api-ping', null, '203.0.113.51');

        $response->assertStatus(403);
        $this->assertSame(0, MonitorApiBlockEvent::count());
    }

    public function test_blocked_ip_response_is_never_the_html_abort_page(): void
    {
        BlockedIp::create(['ip' => '203.0.113.52', 'source' => 'manual']);

        $response = $this->callApi('/__test/api-ping', null, '203.0.113.52');

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    // --- Invalid token (item 6) ---

    public function test_an_invalid_token_falls_back_to_ip_grouping_and_counts_as_a_scraper_signal(): void
    {
        $response = $this->callApi('/__test/api-ping', 'totally-invalid-token-value', '198.51.100.30');

        $response->assertOk();

        $this->assertSame(1, Monitor::count());
        $monitor = Monitor::first();
        $this->assertNull($monitor->token_id);
        $this->assertContains(
            ScraperSignalDetector::SIGNAL_INVALID_API_TOKEN,
            $monitor->data['flags']['scraper_signals']
        );
    }

    public function test_an_expired_token_is_treated_as_invalid(): void
    {
        $token = $this->createToken(['expires_at' => now()->subDay()]);

        $response = $this->callApi('/__test/api-ping', $token['plain'], '198.51.100.31');

        $response->assertOk();

        $monitor = Monitor::first();
        $this->assertNull($monitor->token_id);
        $this->assertContains(
            ScraperSignalDetector::SIGNAL_INVALID_API_TOKEN,
            $monitor->data['flags']['scraper_signals']
        );
    }

    public function test_a_request_without_any_bearer_token_never_gets_the_invalid_token_signal(): void
    {
        $this->callApi('/__test/api-ping', null, '198.51.100.32')->assertOk();

        $monitor = Monitor::first();
        $this->assertNotContains(
            ScraperSignalDetector::SIGNAL_INVALID_API_TOKEN,
            $monitor->data['flags']['scraper_signals']
        );
    }

    // --- Sanctum absent (item 7) ---

    /**
     * Simula `laravel/sanctum` NÃO instalado sobrescrevendo o único seam
     * que `handle()` usa pra decidir isso (`sanctumAvailable()`) — sem
     * isso, `Laravel\Sanctum\PersonalAccessToken` está sempre presente no
     * autoload deste pacote de testes (é `require-dev` DO PACOTE, pra
     * poder testar o caminho feliz acima), então não tem como desinstalar
     * de verdade só pra este teste. Prova que, com o seam retornando
     * `false`, nenhuma classe `Laravel\Sanctum\*` é tocada e a request
     * degrada pro fallback por IP sem nenhuma exceção.
     */
    protected function bindApiMethodWithoutSanctum(): void
    {
        $this->app->bind(MonitorApiMethod::class, function ($app) {
            return new class($app->make(AnonymousVisitorTracker::class), $app->make(ApiTokenVisitorTracker::class)) extends MonitorApiMethod
            {
                protected function sanctumAvailable(): bool
                {
                    return false;
                }
            };
        });
    }

    public function test_degrades_to_ip_tracking_with_zero_exceptions_when_sanctum_is_unavailable(): void
    {
        $this->bindApiMethodWithoutSanctum();
        $token = $this->createToken();

        // Mesmo mandando um Bearer token "válido" de verdade, o seam
        // desligado nunca chega a consultar Sanctum — vira IP puro, sem
        // sinal de invalid_api_token (não dá pra saber se era válido ou
        // não sem o pacote presente, então não acusamos nada).
        $response = $this->callApi('/__test/api-ping', $token['plain'], '198.51.100.40');

        $response->assertOk();
        $this->assertSame(1, Monitor::count());
        $monitor = Monitor::first();
        $this->assertNull($monitor->token_id);
        $this->assertNotContains(
            ScraperSignalDetector::SIGNAL_INVALID_API_TOKEN,
            $monitor->data['flags']['scraper_signals']
        );
    }

    public function test_degrades_gracefully_even_when_the_ip_is_blocked(): void
    {
        $this->bindApiMethodWithoutSanctum();
        BlockedIp::create(['ip' => '203.0.113.60', 'source' => 'manual']);
        $token = $this->createToken();

        $response = $this->callApi('/__test/api-ping', $token['plain'], '203.0.113.60');

        $response->assertStatus(403);
        // Sem Sanctum disponível, nunca sabemos se o token "era válido" -
        // nenhum evento é gravado (mesmo path de "sem token nenhum").
        $this->assertSame(0, MonitorApiBlockEvent::count());
    }

    // --- The package's own dashboard route is never self-tracked ---

    public function test_the_packages_own_handler_route_is_not_tracked_by_the_api_middleware(): void
    {
        config(['monitor.local_token' => 'test-local-token']);

        $this->postJson('/monitor/handler', ['action' => 'getData'], [
            'Authorization' => 'Bearer test-local-token',
        ])->assertOk();

        $this->assertSame(0, Monitor::count());
    }

    // --- Ignored IPs bypass everything, same as the web middleware ---

    public function test_an_ignored_ip_is_never_tracked(): void
    {
        config(['monitor.ignore_ips' => ['203.0.113.70']]);

        $this->callApi('/__test/api-ping', null, '203.0.113.70')->assertOk();

        $this->assertSame(0, Monitor::count());
    }
}
