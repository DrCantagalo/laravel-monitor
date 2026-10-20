<?php

namespace Drcantagalo\LaravelMonitor\Tests\Feature;

use Drcantagalo\LaravelMonitor\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * laravel-monitor 399: `scraper_cumulative_visits_threshold` foi removida
 * do template (`src/config/monitor.php`) junto com o sinal
 * `high_cumulative_visits` que ela alimentava (ver
 * `Support\ScraperSignalDetector`). `monitor:update` já tratava qualquer
 * chave presente na config publicada do cliente mas ausente no template
 * atual como `removedKeys` (só reporta no resumo, nunca falha por isso —
 * ver `MonitorUpdateCommand::handle()`/`summarize()`) — este teste
 * confirma que esse comportamento já existente continua valendo pra essa
 * chave específica: uma instalação "velha", com o `config/monitor.php`
 * publicado ainda contendo a chave removida, continua rodando
 * `monitor:update` sem erro/crash.
 */
class MonitorUpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach ([config_path('monitor.php'), storage_path('monitor/installation.json')] as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    /**
     * `ensureMainDomain()` pergunta interativamente (`$this->ask`) quando
     * `main_domain` não está em `storage/monitor/installation.json` —
     * pré-populamos a chave pra manter o teste não-interativo, já que o
     * que está sob teste aqui é o tratamento da chave removida em
     * `config/monitor.php`, não essa pergunta (já teria outro teste
     * dedicado, se existisse).
     */
    protected function writeInstallationJsonWithMainDomain(): void
    {
        $storagePath = storage_path('monitor');

        if (! File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        File::put(storage_path('monitor/installation.json'), json_encode([
            'lang' => 'en',
            'main_domain' => 'example.com',
        ]));
    }

    public function test_does_not_error_when_published_config_still_has_the_removed_key(): void
    {
        $this->writeInstallationJsonWithMainDomain();

        File::put(config_path('monitor.php'), <<<'PHP'
            <?php

            return [
                'version' => '0.73.0',
                'scraper_cumulative_visits_threshold' => 5000,
                'track_visits' => true,
            ];
            PHP);

        $exitCode = Artisan::call('monitor:update');

        $this->assertSame(0, $exitCode);

        // A chave removida continua no arquivo do cliente (o comando
        // nunca apaga chaves existentes, só reporta — ver `removed_keys`
        // abaixo) e nada quebra por sua presença.
        $published = require config_path('monitor.php');
        $this->assertSame(5000, $published['scraper_cumulative_visits_threshold']);
        $this->assertTrue($published['track_visits']);
    }

    public function test_reports_the_removed_key_without_failing(): void
    {
        $this->writeInstallationJsonWithMainDomain();

        File::put(config_path('monitor.php'), <<<'PHP'
            <?php

            return [
                'version' => '0.73.0',
                'scraper_cumulative_visits_threshold' => 5000,
            ];
            PHP);

        $exitCode = Artisan::call('monitor:update');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('scraper_cumulative_visits_threshold', Artisan::output());
    }

    public function test_still_adds_new_keys_and_bumps_version_despite_the_stale_removed_key(): void
    {
        $this->writeInstallationJsonWithMainDomain();

        File::put(config_path('monitor.php'), <<<'PHP'
            <?php

            return [
                'version' => '0.1.0',
                'scraper_cumulative_visits_threshold' => 5000,
            ];
            PHP);

        $exitCode = Artisan::call('monitor:update');

        $this->assertSame(0, $exitCode);

        $published = require config_path('monitor.php');
        $this->assertNotSame('0.1.0', $published['version']);
        // Chave viva que não existia na config publicada original —
        // confirma que a manutenção normal do comando (adicionar chaves
        // novas) não é afetada pela presença da chave removida.
        $this->assertArrayHasKey('scraper_signal_threshold', $published);
        // A chave removida não some do arquivo do cliente sozinha (não é
        // responsabilidade deste comando apagar chaves — decisão manual).
        $this->assertArrayHasKey('scraper_cumulative_visits_threshold', $published);
    }
}
