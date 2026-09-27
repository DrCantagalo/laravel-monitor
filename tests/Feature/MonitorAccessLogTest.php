<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorAccessLog;
use Drcantagalo\LaravelMonitor\Support\AccessLogger;
use Drcantagalo\LaravelMonitor\Support\DataPruner;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 249: transparência de leitura — `monitor_access_logs`
 * registra emissão de read-token, primeiro uso de cada read-token pelo
 * navegador, e toda leitura feita com o local_token permanente. Ver
 * `MonitorController::issueReadToken/maybeLogReadTokenFirstUse/getAccessLog`,
 * `Support\AccessLogger`, `Support\DataPruner::pruneAccessLogs()`.
 */
class MonitorAccessLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);
    }

    public function test_issue_read_token_logs_read_token_issued_with_declared_by(): void
    {
        $response = $this->callHandler(['action' => 'issueReadToken', 'declared_by' => 'user@example.com']);
        $response->assertOk();

        $this->assertSame(1, MonitorAccessLog::count());

        $log = MonitorAccessLog::first();
        $this->assertSame(AccessLogger::KIND_READ_TOKEN_ISSUED, $log->kind);
        $this->assertSame('issueReadToken', $log->action);
        $this->assertSame('user@example.com', $log->declared_by);
        $this->assertNotNull($log->token_ref);
        $this->assertNotNull($log->ip);
    }

    public function test_first_use_of_a_read_token_logs_browser_evidence_once(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        // A emissão em si já gerou 1 linha (read_token_issued).
        $this->assertSame(1, MonitorAccessLog::count());

        $first = $this->postJson('/monitor/handler', ['action' => 'getData'], [
            'Authorization' => 'Bearer '.$token,
            'User-Agent' => 'Mozilla/5.0 Test Browser',
            'Origin' => 'https://cantagalo.it',
        ]);
        $first->assertOk();

        $this->assertSame(2, MonitorAccessLog::count());
        $firstUse = MonitorAccessLog::where('kind', AccessLogger::KIND_READ_TOKEN_FIRST_USE)->first();
        $this->assertNotNull($firstUse);
        $this->assertSame('getData', $firstUse->action);
        $this->assertSame('Mozilla/5.0 Test Browser', $firstUse->user_agent);
        $this->assertSame('https://cantagalo.it', $firstUse->origin);

        // Mesmo token_ref da emissão, pra ligar as duas linhas.
        $issued = MonitorAccessLog::where('kind', AccessLogger::KIND_READ_TOKEN_ISSUED)->first();
        $this->assertSame($issued->token_ref, $firstUse->token_ref);

        // Segunda chamada com o MESMO token não gera nova linha de first-use.
        $second = $this->postJson('/monitor/handler', ['action' => 'getData'], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $second->assertOk();

        $this->assertSame(2, MonitorAccessLog::count());
    }

    public function test_get_access_log_as_first_call_does_not_consume_the_first_use_flag(): void
    {
        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        // getAccessLog é a primeira chamada com este token — não deve
        // gerar read_token_first_use nem se auto-registrar.
        $accessLogCall = $this->postJson('/monitor/handler', ['action' => 'getAccessLog'], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $accessLogCall->assertOk();

        $this->assertSame(0, MonitorAccessLog::where('kind', AccessLogger::KIND_READ_TOKEN_FIRST_USE)->count());

        // A PRÓXIMA action de leitura de verdade com o mesmo token ainda
        // registra o primeiro uso normalmente.
        $realRead = $this->postJson('/monitor/handler', ['action' => 'getData'], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $realRead->assertOk();

        $this->assertSame(1, MonitorAccessLog::where('kind', AccessLogger::KIND_READ_TOKEN_FIRST_USE)->count());
    }

    public function test_local_token_read_action_logs_local_token_read_with_declared_by(): void
    {
        $response = $this->callHandler(['action' => 'getData', 'declared_by' => 'AI triage run #7']);
        $response->assertOk();

        $log = MonitorAccessLog::where('kind', AccessLogger::KIND_LOCAL_TOKEN_READ)->first();
        $this->assertNotNull($log);
        $this->assertSame('getData', $log->action);
        $this->assertSame('AI triage run #7', $log->declared_by);
        $this->assertNull($log->token_ref);
    }

    public function test_local_token_write_action_does_not_log_a_read_line(): void
    {
        $this->callHandler(['action' => 'clearData']);

        $this->assertSame(0, MonitorAccessLog::count());
    }

    public function test_get_access_log_never_generates_its_own_line_via_local_token(): void
    {
        $response = $this->callHandler(['action' => 'getAccessLog']);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSame(0, MonitorAccessLog::count());
    }

    public function test_get_access_log_lists_most_recent_first_and_accepts_both_token_types(): void
    {
        $this->callHandler(['action' => 'getData']);
        $this->callHandler(['action' => 'getPages']);

        $response = $this->callHandler(['action' => 'getAccessLog']);
        $response->assertOk();

        $actions = collect($response->json('data'))->pluck('action')->all();
        $this->assertSame(['getPages', 'getData'], $actions);

        $issue = $this->callHandler(['action' => 'issueReadToken']);
        $token = $issue->json('token');

        $viaReadToken = $this->postJson('/monitor/handler', ['action' => 'getAccessLog'], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $viaReadToken->assertOk();
        $viaReadToken->assertJsonPath('success', true);
    }

    public function test_clear_data_and_prune_data_never_delete_access_log_rows(): void
    {
        $this->callHandler(['action' => 'getData']);
        $countBefore = MonitorAccessLog::count();
        $this->assertGreaterThan(0, $countBefore);

        Monitor::create(['data' => []]);

        $clear = $this->callHandler(['action' => 'clearData']);
        $clear->assertOk();

        // clearData em si soma mais uma linha local_token_read? Não —
        // clearData não está em ACCESS_LOGGED_ACTIONS (é escrita). A
        // contagem de linhas de leitura anteriores deve continuar intacta.
        $this->assertSame($countBefore, MonitorAccessLog::count());

        $prune = $this->callHandler(['action' => 'pruneData', 'older_than_days' => 0]);
        $prune->assertOk();

        $this->assertSame($countBefore, MonitorAccessLog::count());
    }

    public function test_a_read_action_is_never_broken_by_a_missing_access_logs_table(): void
    {
        Schema::drop('monitor_access_logs');

        $response = $this->callHandler(['action' => 'getData']);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $accessLog = $this->callHandler(['action' => 'getAccessLog']);
        $accessLog->assertOk();
        $accessLog->assertJsonPath('data', []);
        $accessLog->assertJsonPath('meta.total', 0);
    }

    public function test_prune_access_logs_respects_retention_config(): void
    {
        $old = MonitorAccessLog::create([
            'accessed_at' => now()->subDays(100),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '127.0.0.1',
        ]);
        $recent = MonitorAccessLog::create([
            'accessed_at' => now()->subDays(10),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '127.0.0.1',
        ]);

        config(['monitor.access_log_retention_days' => 90]);
        $deleted = DataPruner::pruneAccessLogs();

        $this->assertSame(1, $deleted);
        $this->assertFalse(MonitorAccessLog::whereKey($old->id)->exists());
        $this->assertTrue(MonitorAccessLog::whereKey($recent->id)->exists());
    }

    public function test_prune_access_logs_disabled_when_retention_is_zero(): void
    {
        MonitorAccessLog::create([
            'accessed_at' => now()->subDays(1000),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '127.0.0.1',
        ]);

        config(['monitor.access_log_retention_days' => 0]);
        $deleted = DataPruner::pruneAccessLogs();

        $this->assertSame(0, $deleted);
        $this->assertSame(1, MonitorAccessLog::count());
    }

    public function test_maybe_cleanup_also_prunes_access_logs(): void
    {
        MonitorAccessLog::create([
            'accessed_at' => now()->subDays(200),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '127.0.0.1',
        ]);

        config(['monitor.access_log_retention_days' => 90]);
        Cache::forget('monitor:data-prune:last-run');

        DataPruner::maybeCleanup();

        $this->assertSame(0, MonitorAccessLog::count());
    }

    public function test_pruning_via_only_blocked_or_prune_data_never_reaches_access_logs(): void
    {
        // DataPruner::prune() (usado por pruneData e monitor:prune) nunca
        // chama pruneAccessLogs() — só maybeCleanup() faz isso.
        MonitorAccessLog::create([
            'accessed_at' => now()->subDays(1000),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '127.0.0.1',
        ]);

        DataPruner::prune(0, false);
        DataPruner::prune(0, true);

        $this->assertSame(1, MonitorAccessLog::count());
    }

    public function test_command_lists_entries(): void
    {
        MonitorAccessLog::create([
            'accessed_at' => now(),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '9.9.9.9',
            'declared_by' => 'AI triage run #1',
        ]);

        Artisan::call('monitor:access-log');
        $output = Artisan::output();

        $this->assertStringContainsString('getData', $output);
        $this->assertStringContainsString('9.9.9.9', $output);
    }

    public function test_command_purge_requires_confirmation_and_deletes_all(): void
    {
        MonitorAccessLog::create([
            'accessed_at' => now(),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '9.9.9.9',
        ]);

        $this->artisan('monitor:access-log', ['--purge' => true])
            ->expectsConfirmation('This will permanently delete all 1 access log entries. Continue?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(0, MonitorAccessLog::count());
    }

    public function test_command_purge_aborts_when_not_confirmed(): void
    {
        MonitorAccessLog::create([
            'accessed_at' => now(),
            'kind' => AccessLogger::KIND_LOCAL_TOKEN_READ,
            'action' => 'getData',
            'ip' => '9.9.9.9',
        ]);

        $this->artisan('monitor:access-log', ['--purge' => true])
            ->expectsConfirmation('This will permanently delete all 1 access log entries. Continue?', 'no')
            ->assertExitCode(1);

        $this->assertSame(1, MonitorAccessLog::count());
    }
}
