<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Support\DashboardAccess;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * laravel-monitor 317: `monitor:dashboard on|off|status` + o handler
 * bloqueando com o código estável `dashboard_access_disabled` quando o
 * acesso está desligado. Ver docblock de `Support\DashboardAccess` pra
 * por que isso é separado de `config('monitor.dashboard.enabled')`.
 */
class MonitorDashboardAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $path = storage_path('monitor/dashboard-access.json');

        if (File::exists($path)) {
            File::delete($path);
        }

        parent::tearDown();
    }

    public function test_default_with_no_file_is_enabled(): void
    {
        $this->assertTrue(DashboardAccess::isEnabled());
        $this->assertSame('on', DashboardAccess::status()['state']);
    }

    public function test_command_off_disables_access(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'off'])->assertExitCode(0);

        $this->assertFalse(DashboardAccess::isEnabled());
        $this->assertSame('off', DashboardAccess::status()['state']);
    }

    public function test_command_on_after_off_re_enables_access(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'off'])->assertExitCode(0);
        $this->artisan('monitor:dashboard', ['state' => 'on'])->assertExitCode(0);

        $this->assertTrue(DashboardAccess::isEnabled());
        $this->assertNull(DashboardAccess::status()['expires_at']);
    }

    public function test_on_with_for_sets_an_expiration(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 9, 10, 0, 0));

        $this->artisan('monitor:dashboard', ['state' => 'on', '--for' => '2h'])->assertExitCode(0);

        $status = DashboardAccess::status();
        $this->assertSame('on', $status['state']);
        $this->assertTrue($status['expires_at']->equalTo(Carbon::create(2026, 10, 9, 12, 0, 0)));
    }

    public function test_access_closes_by_itself_once_the_for_duration_expires(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 9, 10, 0, 0));
        $this->artisan('monitor:dashboard', ['state' => 'on', '--for' => '30m'])->assertExitCode(0);

        $this->assertTrue(DashboardAccess::isEnabled());

        Carbon::setTestNow(Carbon::create(2026, 10, 9, 10, 31, 0));

        $this->assertFalse(DashboardAccess::isEnabled());
        $this->assertSame('off', DashboardAccess::status()['state']);
    }

    public function test_invalid_for_value_fails_loud(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'on', '--for' => 'banana'])->assertExitCode(1);

        // Nada deve ter sido gravado por uma tentativa inválida.
        $this->assertTrue(DashboardAccess::isEnabled());
    }

    public function test_for_with_off_is_rejected(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'off', '--for' => '2h'])->assertExitCode(1);

        $this->assertTrue(DashboardAccess::isEnabled());
    }

    public function test_invalid_state_argument_fails_loud(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'maybe'])->assertExitCode(1);
    }

    public function test_status_command_runs_without_error_on_and_off(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'status'])->assertExitCode(0);

        $this->artisan('monitor:dashboard', ['state' => 'off'])->assertExitCode(0);
        $this->artisan('monitor:dashboard', ['state' => 'status'])->assertExitCode(0);
    }

    public function test_handler_rejects_every_action_with_stable_code_when_access_is_off(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'off']);

        $response = $this->callHandler(['action' => 'getData']);

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('code', 'dashboard_access_disabled');
    }

    public function test_handler_works_normally_once_access_is_back_on(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'off']);
        $this->artisan('monitor:dashboard', ['state' => 'on']);

        $response = $this->callHandler(['action' => 'getData']);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_handler_blocks_even_with_a_valid_local_token(): void
    {
        $this->artisan('monitor:dashboard', ['state' => 'off']);

        // Mesmo com o local_token certo (ver TestCase::callHandler), o
        // bloqueio é incondicional — não é uma checagem de auth.
        $response = $this->callHandler(['action' => 'getData']);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'dashboard_access_disabled');
    }
}
