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
