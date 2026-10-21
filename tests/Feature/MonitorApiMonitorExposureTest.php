<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-monitor 400, item 8: `getUserMonitors`/`getIpMonitors`/
 * `getMonitorQueue` (via `MonitorController::hydrateMonitorRows()`,
 * compartilhado pelos três) expõem `is_api_monitor`/`api_token_name` pra
 * cada linha — flag + nome do token Sanctum de um Monitor de API (grupo
 * `token_id`, task 400), consistente com os outros campos já expostos
 * ali (`kind`/`tags`/`source`/etc).
 */
class MonitorApiMonitorExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.local_token' => 'test-local-token']);

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

    protected function createTokenRow(string $name, int $tokenableId = 7): int
    {
        return DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $tokenableId,
            'name' => $name,
            'token' => hash('sha256', $name.'-secret'),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_get_user_monitors_exposes_is_api_monitor_and_token_name(): void
    {
        $tokenId = $this->createTokenRow('mobile-app', 7);

        $apiMonitor = Monitor::create(['data' => ['user_id' => 7], 'token_id' => $tokenId]);
        $humanMonitor = Monitor::create(['data' => ['user_id' => 7]]);

        $response = $this->callHandler(['action' => 'getUserMonitors', 'user_id' => 7]);

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows[$apiMonitor->id]['is_api_monitor']);
        $this->assertSame('mobile-app', $rows[$apiMonitor->id]['api_token_name']);

        $this->assertFalse($rows[$humanMonitor->id]['is_api_monitor']);
        $this->assertNull($rows[$humanMonitor->id]['api_token_name']);
    }

    public function test_get_ip_monitors_exposes_is_api_monitor_and_token_name(): void
    {
        $tokenId = $this->createTokenRow('integration-script', 9);

        $apiMonitor = Monitor::create(['data' => ['user_id' => 9], 'token_id' => $tokenId]);
        DB::table('monitor_visit_ips')->insert([
            'monitor_id' => $apiMonitor->id,
            'ip' => '198.51.100.9',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->callHandler(['action' => 'getIpMonitors', 'ip' => '198.51.100.9']);

        $response->assertOk();
        $row = collect($response->json('data'))->first();

        $this->assertTrue($row['is_api_monitor']);
        $this->assertSame('integration-script', $row['api_token_name']);
    }

    public function test_a_token_monitor_without_a_matching_personal_access_token_row_has_a_null_token_name(): void
    {
        // token_id "órfão" (linha do Sanctum apagada depois) — não deve
        // quebrar a listagem, só devolver o nome como null.
        $apiMonitor = Monitor::create(['data' => ['user_id' => 7], 'token_id' => 99999]);

        $response = $this->callHandler(['action' => 'getUserMonitors', 'user_id' => 7]);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $apiMonitor->id);

        $this->assertTrue($row['is_api_monitor']);
        $this->assertNull($row['api_token_name']);
    }
}
