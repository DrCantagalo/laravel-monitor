<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Support\ListingsCache;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/**
 * laravel-monitor 262 (v0.54.0): novo read action `getConfig` — config
 * efetiva do cliente pra aba "Data" do dashboard, restrita a uma
 * whitelist fixa de chaves (nunca `config('monitor')` inteiro), com
 * valores sensíveis mascarados. Ver
 * `MonitorController::getConfig()`/`buildConfigResult()`/
 * `CONFIG_WHITELIST`/`maskConfigValue()`.
 */
class MonitorGetConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mesma lista de chaves de `MonitorController::CONFIG_WHITELIST` —
     * duplicada aqui de propósito (não lida via Reflection da constante)
     * pra o teste continuar servindo de guarda mesmo se alguém adicionar
     * uma chave nova à constante sem querer: `test_whitelist_is_exactly_the_expected_set_of_keys`
     * falha nesse caso e força uma decisão consciente.
     */
    protected const EXPECTED_KEYS = [
        'visits_retention_days', 'access_log_retention_days', 'data_prune_interval_hours',
        'data_prune_max_rows_per_run', 'blocked_ips_cleanup_interval_hours',
        'scraper_frequency_window_seconds', 'scraper_frequency_threshold', 'scraper_signal_threshold',
        'scraper_cumulative_visits_threshold', 'scraper_known_bot_user_agents',
        'auto_block_signal_threshold', 'auto_block_strike_decay_cooldown_days',
        'auto_block_permanent_after_lifetime_offenses',
        'ai_recheck_min_new_hits',
        'listings_cache_ttl_minutes', 'pages_cache_ttl_minutes', 'data_totals_cache_ttl_seconds',
        'block_results_cache_ttl_seconds', 'blocked_ip_cache_ttl',
        'track_visits', 'track_authenticated_user', 'visit_max_paths', 'denylist_format',
        'denylist_export_interval_hours', 'ignore_ips', 'denylist_path',
    ];

    protected function tearDown(): void
    {
        $publishedPath = config_path('monitor.php');

        if (File::exists($publishedPath)) {
            File::delete($publishedPath);
        }

        parent::tearDown();
    }

    public function test_whitelist_is_exactly_the_expected_set_of_keys(): void
    {
        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertEqualsCanonicalizing(self::EXPECTED_KEYS, array_keys($response->json('config')));
    }

    public function test_identity_and_infra_keys_never_leak_through(): void
    {
        $response = $this->callHandler(['action' => 'getConfig']);

        $keys = array_keys($response->json('config'));

        // Chaves de identidade/infra — item 2 da task 262, ficam de fora
        // de propósito.
        foreach (['dashboard_origin', 'remember_cookie', 'remember_cookie_days', 'skip_session_key', 'local_token', 'read_token_ttl_minutes', 'dashboard', 'version'] as $identityKey) {
            $this->assertNotContains($identityKey, $keys, "'{$identityKey}' não deveria aparecer em getConfig");
        }

        // Nunca a config inteira do pacote dumpada crua.
        $this->assertLessThan(count(config('monitor')), count($keys));
    }

    public function test_ignore_ips_is_masked_to_a_count_never_the_raw_list(): void
    {
        config(['monitor.ignore_ips' => ['203.0.113.7', '198.51.100.1', '10.0.0.1']]);
        ListingsCache::invalidate();

        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $this->assertSame('3 IPs', $response->json('config.ignore_ips.value'));
        $this->assertSame('0 IPs', $response->json('config.ignore_ips.default'));
        $this->assertTrue($response->json('config.ignore_ips.customized'));

        // Nenhum IP individual vaza em lugar nenhum da resposta.
        $body = $response->getContent();
        $this->assertStringNotContainsString('203.0.113.7', $body);
        $this->assertStringNotContainsString('198.51.100.1', $body);
        $this->assertStringNotContainsString('10.0.0.1', $body);
    }

    public function test_denylist_path_is_masked_to_filename_never_the_absolute_path(): void
    {
        config(['monitor.denylist_path' => '/var/www/example.test/storage/app/monitor/denylist.conf']);
        ListingsCache::invalidate();

        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $this->assertSame('denylist.conf', $response->json('config.denylist_path.value'));
        $this->assertTrue($response->json('config.denylist_path.customized'));

        $this->assertStringNotContainsString('/var/www/example.test', $response->getContent());
    }

    public function test_scraper_known_bot_user_agents_exposes_only_a_count(): void
    {
        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $entry = $response->json('config.scraper_known_bot_user_agents');

        $this->assertIsInt($entry['value']);
        $this->assertGreaterThan(0, $entry['value']);
        $this->assertSame($entry['default'], $entry['value']);
        $this->assertFalse($entry['customized']);

        // A lista crua de user agents nunca aparece na resposta.
        $this->assertStringNotContainsString('ahrefsbot', $response->getContent());
    }

    public function test_customized_is_false_when_value_matches_the_package_default(): void
    {
        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $entry = $response->json('config.ai_recheck_min_new_hits');

        $this->assertSame(20, $entry['value']);
        $this->assertSame(20, $entry['default']);
        $this->assertFalse($entry['customized']);
    }

    public function test_customized_is_true_when_value_differs_from_the_package_default(): void
    {
        config(['monitor.visits_retention_days' => 45]);
        ListingsCache::invalidate();

        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $entry = $response->json('config.visits_retention_days');

        $this->assertSame(45, $entry['value']);
        $this->assertSame(0, $entry['default']);
        $this->assertTrue($entry['customized']);
    }

    public function test_every_key_is_missing_from_file_when_config_was_never_published(): void
    {
        $this->assertFileDoesNotExist(config_path('monitor.php'));

        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $this->assertFalse($response->json('meta.config_published'));

        foreach ($response->json('config') as $key => $entry) {
            $this->assertTrue($entry['missing_from_file'], "'{$key}' deveria estar missing_from_file quando o arquivo nunca foi publicado");
        }
    }

    public function test_missing_from_file_is_false_only_for_keys_present_in_the_published_file(): void
    {
        File::put(config_path('monitor.php'), "<?php\n\nreturn [\n    'version' => '0.1.0',\n    'track_visits' => false,\n];\n");
        ListingsCache::invalidate();

        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $this->assertTrue($response->json('meta.config_published'));

        $this->assertFalse($response->json('config.track_visits.missing_from_file'));
        $this->assertTrue($response->json('config.visits_retention_days.missing_from_file'));
        $this->assertTrue($response->json('config.ignore_ips.missing_from_file'));
    }

    public function test_env_names_are_reported_only_for_keys_that_actually_read_one(): void
    {
        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $this->assertSame('MONITOR_IGNORE_IPS', $response->json('config.ignore_ips.env'));
        $this->assertNull($response->json('config.track_visits.env'));
        $this->assertNull($response->json('config.denylist_path.env'));
    }

    public function test_meta_reports_package_and_config_version_with_a_divergence_flag(): void
    {
        $probe = $this->callHandler(['action' => 'getData']);
        $packageVersion = $probe->json('package_version');

        if ($packageVersion === null) {
            $this->markTestSkipped('InstalledVersions não resolveu o pacote raiz neste ambiente de teste.');
        }

        config(['monitor.version' => $packageVersion]);
        ListingsCache::invalidate();
        $same = $this->callHandler(['action' => 'getConfig']);

        $same->assertOk();
        $this->assertSame($packageVersion, $same->json('meta.package_version'));
        $this->assertSame($packageVersion, $same->json('meta.config_version'));
        $this->assertFalse($same->json('meta.version_diverged'));

        config(['monitor.version' => $packageVersion.'-stale']);
        ListingsCache::invalidate();
        $diverged = $this->callHandler(['action' => 'getConfig']);

        $diverged->assertOk();
        $this->assertTrue($diverged->json('meta.version_diverged'));
    }

    public function test_meta_reports_whether_config_is_currently_cached(): void
    {
        $response = $this->callHandler(['action' => 'getConfig']);

        $response->assertOk();
        $this->assertFalse($response->json('meta.config_cached'));
    }

    public function test_result_is_cached_like_the_other_listings_actions(): void
    {
        $first = $this->callHandler(['action' => 'getConfig']);
        $first->assertOk();

        config(['monitor.visits_retention_days' => 999]);

        // Sem invalidar o cache de listagens, a segunda chamada ainda
        // deve refletir o valor cacheado da primeira (mesmo esquema de
        // getTableStats/getVisitorsByIp).
        $second = $this->callHandler(['action' => 'getConfig']);
        $second->assertOk();
        $this->assertSame(
            $first->json('config.visits_retention_days.value'),
            $second->json('config.visits_retention_days.value'),
            'segunda chamada deveria vir do cache, ainda vendo o valor antigo'
        );

        ListingsCache::invalidate();

        $third = $this->callHandler(['action' => 'getConfig']);
        $third->assertOk();
        $this->assertSame(999, $third->json('config.visits_retention_days.value'));
    }

    public function test_accepts_ephemeral_read_token(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $response = $this->callHandler(['action' => 'getConfig'], $token);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/monitor/handler', ['action' => 'getConfig']);

        $response->assertStatus(401);
    }
}
