<?php

namespace Drcantagalo\LaravelMonitor\Tests;

use Drcantagalo\LaravelMonitor\MonitorServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base de testes do próprio pacote (laravel-monitor 242, v0.50.0) — usa
 * Orchestra Testbench pra rodar `src/Http/Controllers/MonitorController`
 * dentro de uma app Laravel mínima, sem depender do harness
 * (`laravel-monitor-harness`, que só existe pra validar um tag já
 * publicado). `MonitorServiceProvider::boot()` já registra as migrations
 * (`loadMigrationsFrom`) e a rota `/monitor/handler` (`loadRoutesFrom`)
 * sozinho — Testbench só precisa saber que o provider existe
 * (`getPackageProviders`) e que a conexão `testing` (ver `phpunit.xml`,
 * `DB_CONNECTION=testing`) é sqlite `:memory:`.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [MonitorServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // laravel-monitor 258 (v0.53.0): sem isso o SQLite de teste
            // não força FOREIGN KEY (PRAGMA foreign_keys), então um
            // `cascadeOnDelete()` (monitor_page_hits/monitor_visit_ips/
            // monitor_visits/monitor_labels, todos com FK pra `monitors`)
            // nunca dispara aqui mesmo estando correto — produção real
            // (MySQL) sempre enforça, então sem isto os testes davam falso
            // positivo pra qualquer cenário que dependesse do cascade.
            'foreign_key_constraints' => true,
        ]);
    }

    protected function callHandler(array $params, string $token = 'test-local-token')
    {
        config(['monitor.local_token' => 'test-local-token']);

        return $this->postJson('/monitor/handler', $params, [
            'Authorization' => 'Bearer '.$token,
        ]);
    }
}
