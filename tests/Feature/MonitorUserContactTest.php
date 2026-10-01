<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * laravel-monitor 272 (v0.57.0): nome/e-mail estáveis entre Monitors do
 * mesmo `user_id` em `getUsers`, `getUserMonitors`, `getIpMonitors` e
 * `getMonitorQueue` (os quatro expõem `user_id`, os três últimos via
 * `hydrateMonitorRows`). `MonitorController::resolveUserContacts()` é o
 * ponto único testado aqui via as actions públicas.
 */
class MonitorUserContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_list_uses_name_from_an_older_monitor_when_the_newest_has_none(): void
    {
        $withName = Monitor::create(['data' => ['user_id' => 7, 'name' => 'Alice', 'email' => 'alice@example.com']]);
        $withName->forceFill(['updated_at' => now()->subHour()])->save();

        $withoutName = Monitor::create(['data' => ['user_id' => 7]]);
        $withoutName->forceFill(['updated_at' => now()])->save();

        $response = $this->callHandler(['action' => 'getUsers']);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('user_id', 7);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    public function test_users_list_name_is_null_when_no_monitor_of_that_user_ever_had_one(): void
    {
        Monitor::create(['data' => ['user_id' => 9]]);

        $response = $this->callHandler(['action' => 'getUsers']);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('user_id', 9);
        $this->assertNull($row['name']);
        $this->assertNull($row['email']);
    }

    /**
     * name/email são resolvidos de forma independente: podem vir de
     * Monitors diferentes do mesmo user_id.
     */
    public function test_users_list_resolves_name_and_email_independently(): void
    {
        $withEmailOnly = Monitor::create(['data' => ['user_id' => 11, 'email' => 'bob@example.com']]);
        $withEmailOnly->forceFill(['updated_at' => now()->subMinutes(30)])->save();

        $withNameOnly = Monitor::create(['data' => ['user_id' => 11, 'name' => 'Bob']]);
        $withNameOnly->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        $response = $this->callHandler(['action' => 'getUsers']);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('user_id', 11);
        $this->assertSame('Bob', $row['name']);
        $this->assertSame('bob@example.com', $row['email']);
    }

    public function test_user_monitors_rows_expose_resolved_contact_not_the_rows_own_data(): void
    {
        $withName = Monitor::create(['data' => ['user_id' => 3, 'name' => 'Carol', 'email' => 'carol@example.com']]);
        $withName->forceFill(['updated_at' => now()->subHour()])->save();

        $deviceWithoutName = Monitor::create(['data' => ['user_id' => 3]]);
        $deviceWithoutName->forceFill(['updated_at' => now()])->save();

        $response = $this->callHandler(['action' => 'getUserMonitors', 'user_id' => 3]);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $deviceWithoutName->id);
        $this->assertSame('Carol', $row['data']['name']);
        $this->assertSame('carol@example.com', $row['data']['email']);
    }

    public function test_ip_monitors_rows_resolve_contact_in_batch_without_n_plus_one(): void
    {
        $withName = Monitor::create(['data' => ['user_id' => 4, 'name' => 'Dave']]);
        $withName->forceFill(['updated_at' => now()->subHour()])->save();
        $withName->recordIp('5.5.5.5');

        $deviceWithoutName = Monitor::create(['data' => ['user_id' => 4]]);
        $deviceWithoutName->forceFill(['updated_at' => now()])->save();
        $deviceWithoutName->recordIp('5.5.5.5');

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->callHandler(['action' => 'getIpMonitors', 'ip' => '5.5.5.5']);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $deviceWithoutName->id);
        $this->assertSame('Dave', $row['data']['name']);

        // Resolução em lote: duas queries pra name/email (não uma por
        // Monitor) somadas às queries já existentes de ips/labels/visits.
        $this->assertLessThan(15, $queries);
    }

    public function test_monitor_without_user_id_has_no_contact_resolved(): void
    {
        $monitor = Monitor::create(['data' => ['name' => 'Orphan']]);
        $monitor->recordIp('6.6.6.6');

        $response = $this->callHandler(['action' => 'getIpMonitors', 'ip' => '6.6.6.6']);

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $monitor->id);
        // Sem user_id, não há contato "resolvido" pra sobrescrever — o
        // data.name cru do próprio Monitor permanece intocado.
        $this->assertSame('Orphan', $row['data']['name']);
    }
}
