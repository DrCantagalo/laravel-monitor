<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Illuminate\Support\Facades\Route;

/**
 * Auditoria manual/sob-demanda (nunca automática) de `monitor_paths`
 * (`trap` e `safe`) contra rotas reais da aplicação consumidora -
 * compartilhada entre `monitor:audit-paths` (comando) e a action
 * `auditPaths` do `MonitorController`, pra achar o mesmo tipo de problema
 * do guard da task 101 (`flagScraperPath`/`markPathSafe` recusando
 * colisão com rota real), só que retroativo e cobrindo casos que aquele
 * guard não pega: uma rota real que existe mas nunca foi visitada ainda
 * não deixa rastro em `Monitor` (sem histórico de tráfego, o guard nunca
 * teria o que checar).
 *
 * Report-only, sem fix automático nenhum - `unflagPath`/`unmarkPathSafe`
 * continuam manuais, chamados por quem revisa o relatório.
 */
class PathsAuditor
{
    /**
     * Pra cada linha de `monitor_paths` (`trap`/`safe`), dois checks, o
     * resultado sendo a UNIÃO dos dois:
     *
     * (a) Route table: o path bate no regex compilado de alguma rota
     * registrada (parâmetros dinâmicos incluídos - usa o compilador do
     * próprio Symfony/Laravel, não reinventa parsing). Pega rota real
     * nunca visitada, que o histórico de tráfego sozinho não veria.
     *
     * (b) Histórico de tráfego: mesmo critério "resolveu como página
     * real" do guard da task 101 (`data.not_found` vazio/false pra
     * alguma chave que bate no path) - pega recurso que nunca passa pelo
     * router do Laravel (arquivo estático, etc.), que a route table
     * sozinha não enxergaria.
     *
     * @return array<int, array{path: string, status: string, matched_route: array{uri: string, source: string}, severity: string}>
     */
    public static function audit(): array
    {
        $routes = self::compiledRoutes();
        $liveTrafficKeys = self::liveTrafficKeys();

        $findings = [];

        MonitorPath::whereIn('status', ['trap', 'safe'])->cursor()->each(function (MonitorPath $row) use ($routes, $liveTrafficKeys, &$findings) {
            $subject = '/'.$row->path;

            $routeMatch = $routes->first(
                fn ($route) => @preg_match($route['regex'], $subject) === 1
            );

            if ($routeMatch !== null) {
                $findings[] = self::finding($row, $routeMatch['uri'], 'route_table');

                return;
            }

            $trafficMatch = $liveTrafficKeys->first(
                fn ($key) => self::pathMatches($key, $row->path)
            );

            if ($trafficMatch !== null) {
                $findings[] = self::finding($row, $trafficMatch, 'traffic');
            }
        });

        return $findings;
    }

    private static function finding(MonitorPath $row, string $matchedUri, string $source): array
    {
        return [
            'path' => $row->path,
            'status' => $row->status,
            'matched_route' => [
                'uri' => $matchedUri,
                'source' => $source,
            ],
            // trap colidindo = risco real de bloquear usuário de verdade
            // (severidade alta); safe colidindo = só informativo, a marca
            // nunca precisaria existir (a rota já é 'clean' por si só).
            'severity' => $row->status === 'trap' ? 'high' : 'info',
        ];
    }

    /**
     * Regex compilado de cada rota registrada, uma vez só (reaproveitado
     * pra cada linha de `monitor_paths` testada, não recompilado a cada
     * iteração). `getCompiled()` só retorna algo depois que a rota já foi
     * usada num dispatch real (nunca acontece num comando artisan
     * standalone) - `toSymfonyRoute()->compile()` (público) força a
     * compilação sem depender disso.
     *
     * Rotas de fallback (`Route::fallback(...)`, usada pela própria app
     * consumidora pra deixar o path chegar até `MonitorMethod`) são
     * excluídas de propósito: o regex delas casa literalmente qualquer
     * path por design, o que tornaria a checagem (a) sempre positiva e
     * inútil.
     *
     * Domínio da rota é ignorado de propósito (não faz parte do regex de
     * path testado aqui) - o bloqueio em si também ignora host
     * (`MonitorMethod::isPathBlocked()`), então o audit precisa espelhar
     * exatamente essa mesma superfície de risco: "essa rota existe em
     * qualquer domínio que a installation atende".
     */
    private static function compiledRoutes()
    {
        return collect(Route::getRoutes())
            ->reject(fn ($route) => $route->isFallback)
            ->map(fn ($route) => [
                'uri' => $route->uri(),
                'regex' => $route->toSymfonyRoute()->compile()->getRegex(),
            ])
            ->unique('uri')
            ->values();
    }

    /**
     * Chaves "host/path" (mesmo formato de `data.page`) que já resolveram
     * como página real (`data.not_found` vazio/false) em pelo menos um
     * hit, em qualquer `Monitor` - mesmo critério do guard da task 101,
     * só que pré-computado uma vez pra todas as linhas de `monitor_paths`
     * em vez de escanear `Monitor` de novo por path.
     */
    private static function liveTrafficKeys()
    {
        $keys = [];

        Monitor::cursor()->each(function (Monitor $monitor) use (&$keys) {
            $notFound = (array) data_get($monitor, 'data.not_found', []);

            foreach (array_keys((array) data_get($monitor, 'data.page', [])) as $key) {
                if (empty($notFound[$key])) {
                    $keys[$key] = true;
                }
            }
        });

        return collect(array_keys($keys));
    }

    /**
     * Mesmo critério de `MonitorController::pathMatches()` (privado
     * naquela classe, duplicado aqui em vez de exposto publicamente só
     * pra esse uso): igualdade ou sufixo "/{$needle}", case-insensitive,
     * pra casar independente do host e da caixa exata da visita real.
     */
    private static function pathMatches(string $haystack, string $needle): bool
    {
        $haystack = strtolower($haystack);
        $needle = strtolower($needle);

        return $haystack === $needle || str_ends_with($haystack, '/'.$needle);
    }
}
