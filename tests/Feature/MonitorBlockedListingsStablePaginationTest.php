<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * laravel-monitor 447 (origem: home-page 443, "Sync trap paths reaplica
 * tudo a cada execução"): `getBlockedPaths`/`getBlockedIps` ordenavam só
 * por `created_at`. `flagScraperPaths` grava um lote inteiro de paths de
 * uma vez só — todas as linhas caem no mesmo segundo de `created_at` — e
 * sem um desempate estável a ordem entre linhas empatadas não é
 * garantida, fazendo a paginação por OFFSET repetir/perder linhas entre
 * páginas (o consumidor, home-page `ApplyTrapPathsJob`, reconstrói o set
 * completo de paths já trap paginando esta action). Estes testes gravam
 * várias linhas com EXATAMENTE o mesmo `created_at` e conferem que
 * paginar tudo com `per_page` pequeno devolve o set completo, sem
 * repetição nem perda, e numa ordem determinística (desempate por `id`
 * desc, mesmo critério das outras listagens deste arquivo).
 */
class MonitorBlockedListingsStablePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_blocked_paths_pagination_is_stable_with_tied_created_at(): void
    {
        $tiedAt = Carbon::create(2026, 11, 5, 12, 0, 0);
        $paths = [];

        for ($i = 1; $i <= 7; $i++) {
            $path = "trap-path-{$i}";
            $paths[] = $path;

            $row = MonitorPath::create(['path' => $path, 'status' => 'trap']);
            $row->timestamps = false;
            $row->created_at = $tiedAt;
            $row->updated_at = $tiedAt;
            $row->save();
        }

        $collected = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->callHandler(['action' => 'getBlockedPaths', 'page' => $page, 'per_page' => 2]);
            $response->assertOk();

            foreach ($response->json('data') as $row) {
                $collected[] = $row['path'];
            }

            if (count($response->json('data')) < 2) {
                break;
            }
        }

        $this->assertCount(7, $collected, 'pagination lost or repeated rows with tied created_at');
        $this->assertSame($paths, array_values(array_reverse($collected)), 'tie-break must be id desc (insertion order reversed)');
    }

    public function test_get_blocked_ips_pagination_is_stable_with_tied_created_at(): void
    {
        $tiedAt = Carbon::create(2026, 11, 5, 12, 0, 0);
        $ips = [];

        for ($i = 1; $i <= 7; $i++) {
            $ip = "10.0.0.{$i}";
            $ips[] = $ip;

            $row = BlockedIp::create(['ip' => $ip, 'source' => 'manual']);
            $row->timestamps = false;
            $row->created_at = $tiedAt;
            $row->updated_at = $tiedAt;
            $row->save();
        }

        $collected = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->callHandler(['action' => 'getBlockedIps', 'page' => $page, 'per_page' => 2]);
            $response->assertOk();

            foreach ($response->json('data') as $row) {
                $collected[] = $row['ip'];
            }

            if (count($response->json('data')) < 2) {
                break;
            }
        }

        $this->assertCount(7, $collected, 'pagination lost or repeated rows with tied created_at');
        $this->assertSame($ips, array_values(array_reverse($collected)), 'tie-break must be id desc (insertion order reversed)');
    }
}
