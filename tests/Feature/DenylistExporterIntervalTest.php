<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Support\DenylistExporter;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 0.77.1: `denylist_export_interval_hours` aceita frações (antes o cast
 * `(int)` zerava 0.5 e truncava 1.5 pra 1), e a mensagem do export
 * Apache manda recarregar o servidor em vez de dizer que não precisa.
 */
class DenylistExporterIntervalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fractional_interval_is_not_truncated_to_zero(): void
    {
        config(['monitor.denylist_export_interval_hours' => 0.5]);
        BlockedIp::create(['ip' => '10.0.0.1', 'blocked_until' => now()->addMinutes(20)]);
        BlockedIp::create(['ip' => '10.0.0.2', 'blocked_until' => now()->addMinutes(40)]);

        $output = DenylistExporter::build('apache');

        $this->assertStringNotContainsString('10.0.0.1', $output);
        $this->assertStringContainsString('Require not ip 10.0.0.2', $output);
    }

    public function test_fractional_interval_above_one_hour_keeps_its_minutes(): void
    {
        config(['monitor.denylist_export_interval_hours' => 1.5]);
        BlockedIp::create(['ip' => '10.0.0.3', 'blocked_until' => now()->addMinutes(75)]);
        BlockedIp::create(['ip' => '10.0.0.4', 'blocked_until' => now()->addMinutes(100)]);

        $output = DenylistExporter::build('nginx');

        $this->assertStringNotContainsString('10.0.0.3', $output);
        $this->assertStringContainsString('deny 10.0.0.4;', $output);
    }

    public function test_apache_export_tells_to_reload(): void
    {
        config(['monitor.denylist_path' => sys_get_temp_dir().'/monitor-denylist-'.uniqid().'.conf']);

        $this->artisan('monitor:export-denylist', ['--format' => 'apache'])
            ->expectsOutputToContain('systemctl reload')
            ->assertExitCode(0);

        @unlink(config('monitor.denylist_path'));
    }
}
