<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Illuminate\Support\Facades\DB;
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
     * `MonitorPath` é processada em chunks (`chunkById`) em vez de
     * `cursor()->each()` sobre a tabela toda de uma vez (mesmo motivo do
     * `chunk()` usado em outros pontos do pacote: teto de memória
     * previsível independente do tamanho de `monitor_paths`).
     *
     * @return array<int, array{path: string, status: string, matched_route: array{uri: string, source: string}, severity: string}>
     */
    public static function audit(): array
    {
        $routes = self::compiledRoutes();
        $liveTrafficSuffixes = self::liveTrafficSuffixIndex();

        $findings = [];

        MonitorPath::whereIn('status', ['trap', 'safe'])->orderBy('id')->chunkById(500, function ($chunk) use ($routes, $liveTrafficSuffixes, &$findings) {
            foreach ($chunk as $row) {
                $subject = '/'.$row->path;

                $routeMatch = $routes->first(
                    fn ($route) => @preg_match($route['regex'], $subject) === 1
                );

                if ($routeMatch !== null) {
                    $findings[] = self::finding($row, $routeMatch['uri'], 'route_table');

                    continue;
                }

                $needle = strtolower($row->path);

                if (isset($liveTrafficSuffixes[$needle])) {
                    $findings[] = self::finding($row, $liveTrafficSuffixes[$needle], 'traffic');
                }
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
     * Índice sufixo -> chave "host/path" original, pra achar em O(1) se
     * algum path de `monitor_paths` (needle, sem host) casa com alguma
     * chave que já resolveu como página real (`not_found = false` em
     * pelo menos um hit) - mesmo critério do guard da task 101, só que
     * lido de `monitor_page_hits` (laravel-monitor 103, mantida em
     * sincronia por `Monitor::booted()`) via uma única query agregada em
     * vez de `Monitor::cursor()->each()` decodificando o JSON de toda a
     * tabela `Monitor` (36.839 linhas em produção - cantagalo.it,
     * installation id=3 - contra só 4.973 em `monitor_paths`: mesma
     * classe de bug das tasks 103/104, ver laravel-monitor 131).
     *
     * A checagem em si (igualdade ou sufixo "/{$needle}",
     * case-insensitive) é a mesma de sempre - só invertida: em vez de,
     * pra cada linha de `monitor_paths`, escanear linearmente todas as
     * chaves de tráfego atrás de uma que combine (`->first(fn...)`,
     * O(monitor_paths × chaves_live)), pré-computamos aqui, uma vez, os
     * sufixos de cada chave de tráfego (mesma técnica de
     * `MonitorController::pathSuffixes()`/`matchesAnySuffix()` da task
     * 103, só que aplicada no sentido oposto: lá o needle é curto e o
     * haystack variável era testado contra um Set de needles; aqui o
     * needle (`$row->path`) é que é testado contra um Set de sufixos
     * pré-computados dos haystacks).
     */
    private static function liveTrafficSuffixIndex(): array
    {
        $index = [];

        DB::table('monitor_page_hits')
            ->where('not_found', false)
            ->distinct()
            ->pluck('path')
            ->each(function (string $path) use (&$index) {
                foreach (self::pathSuffixes($path) as $suffix) {
                    $index[$suffix] ??= $path;
                }
            });

        return $index;
    }

    /**
     * Sufixos de `$haystack` cortados em cada `/` (incluindo o próprio
     * `$haystack`), já em minúsculo. Equivalência com o critério antigo
     * "`$haystack === $needle` ou `$haystack` termina em `/{$needle}`":
     * essa condição é verdadeira sse `$needle` é exatamente algum desses
     * sufixos - mesma prova de `MonitorController::pathSuffixes()`
     * (duplicado aqui por ser a única classe fora do controller que
     * precisa disso).
     */
    private static function pathSuffixes(string $haystack): array
    {
        $haystack = strtolower($haystack);
        $suffixes = [$haystack];
        $offset = 0;

        while (($slash = strpos($haystack, '/', $offset)) !== false) {
            $offset = $slash + 1;
            $suffixes[] = substr($haystack, $offset);
        }

        return $suffixes;
    }
}
