<?php

namespace Drcantagalo\LaravelMonitor\Http\Controllers;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\BlockResult;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Support\DataPruner;
use Drcantagalo\LaravelMonitor\Support\DenylistExporter;
use Drcantagalo\LaravelMonitor\Support\ListingsCache;
use Drcantagalo\LaravelMonitor\Support\PathsAuditor;
use Drcantagalo\LaravelMonitor\Support\ScraperBlocker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonitorController extends Controller
{
    /**
     * Handler principal para ações do monitor
     */
    public function handle(Request $request)
    {
        $token = $request->bearerToken();
        $expected = config('monitor.local_token');
        // input() (não query()): as actions de escrita (updateBlockedIps,
        // clearData, flagScraperPath) chegam via POST com o body em JSON
        // (Http::post do home-page manda 'action' no body, não na query
        // string) - query() só olha a query string e sempre caía no
        // default 'getData', fazendo essas actions silenciosamente
        // virarem getData (successo:true sem bloquear/limpar nada). Ver
        // bugs/laravel-monitor.md.
        $action = $request->input('action', 'getData');

        $isLocalToken = $expected && $token && hash_equals($expected, $token);

        // Token de leitura efêmero (issueReadToken) só é aceito pras
        // actions só-leitura (getData, getPages, getVisitorsByIp,
        // getBlockedIps, getBlockedPaths) — nunca pra
        // clearData/updateBlockedIps/updateRules/issueReadToken, que
        // exigem o local_token permanente.
        $isValidReadToken = in_array($action, [
            'getData', 'getPages', 'getVisitorsByIp', 'getVisitorPaths', 'getBlockedIps', 'getBlockedPaths',
            'getUsers', 'getUserVisits', 'getBlockResults',
        ], true)
            && $token
            && Cache::has("monitor:read-token:{$token}");

        if (! $isLocalToken && ! $isValidReadToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        switch ($action) {
            case 'getData':
                return $this->getData($request);

            case 'getPages':
                return $this->getPages($request);

            case 'getVisitorsByIp':
                return $this->getVisitorsByIp($request);

            case 'getVisitorPaths':
                return $this->getVisitorPaths($request);

            case 'getBlockedIps':
                return $this->getBlockedIps($request);

            case 'getBlockedPaths':
                return $this->getBlockedPaths($request);

            case 'getUsers':
                return $this->getUsers($request);

            case 'getUserVisits':
                return $this->getUserVisits($request);

            case 'getBlockResults':
                return $this->getBlockResults($request);

            case 'clearData':
                return $this->clearData($request);

            case 'pruneData':
                return $this->pruneData($request);

            case 'updateBlockedIps':
                return $this->updateBlockedIps($request);

            case 'unblockIp':
                return $this->unblockIp($request);

            case 'flagScraperPath':
                return $this->flagScraperPath($request);

            case 'flagScraperPaths':
                return $this->flagScraperPaths($request);

            case 'unflagPath':
                return $this->unflagPath($request);

            case 'markPathSafe':
                return $this->markPathSafe($request);

            case 'markPathsSafe':
                return $this->markPathsSafe($request);

            case 'unmarkPathSafe':
                return $this->unmarkPathSafe($request);

            case 'updateRules':
                return $this->updateRules($request);

            case 'issueReadToken':
                return $this->issueReadToken($request);

            case 'exportDenylist':
                return $this->exportDenylist($request);

            case 'auditPaths':
                return $this->auditPaths($request);

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid action',
                ], 400);
        }
    }

    /**
     * Emite um token de leitura efêmero (só-leitura, só pra action
     * getData) pra permitir que o dashboard chame o handler direto do
     * navegador do usuário final sem expor o local_token permanente.
     */
    protected function issueReadToken(Request $request)
    {
        $token = Str::random(64);
        $ttlMinutes = config('monitor.read_token_ttl_minutes', 15);
        $expiresAt = now()->addMinutes($ttlMinutes);

        Cache::put("monitor:read-token:{$token}", true, $expiresAt);

        return response()->json([
            'success' => true,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Task 88: até a 0.9.0 este endpoint devolvia `Monitor::all()` cru —
     * carregava TODAS as linhas de `monitors` como models Eloquent numa
     * response JSON só, o que estourava o `memory_limit` do PHP-FPM em
     * qualquer installation com volume real de tracking (confirmado em
     * produção com 19.5k linhas, ver bugs/laravel-monitor.md). O dump de
     * `data` foi removido de vez (não substituído por amostra paginada —
     * nenhum consumidor conhecido precisa das linhas cruas, só dos
     * agregados abaixo, e o dashboard já os usava só pra somar totais
     * client-side). **Breaking change**, ver CHANGELOG `[0.10.0]`.
     */
    protected function getData(Request $request)
    {
        return response()->json([
            'success' => true,
            'visitors_total' => (int) Monitor::count(),
            'visits_total' => $this->visitsTotal(),
            'sessions_total' => $this->sessionsTotal(),
            'unique_ips_total' => $this->uniqueIpsTotal(),
            // Task 83: soma agregada de monitor_block_results, pro
            // dashboard reusar o mesmo fetch que já alimenta os cards de
            // KPI em vez de bater um endpoint novo só pra esse número.
            'blocked_attempts_total' => $this->blockedAttemptsTotal(),
        ]);
    }

    /**
     * `SUM(data.visits)` de todas as linhas `Monitor`, agregado direto em
     * SQL sobre o blob JSON (`JSON_EXTRACT`/`json_extract`, conforme o
     * driver) — nunca instancia uma linha `Monitor` inteira em PHP, ao
     * contrário do `Monitor::all()` que causava a memory exhaustion
     * original. Mesmo esquema de cache curto/fixo de `blockedAttemptsTotal()`
     * (mutação a cada request rastreada via `Monitor::newVisit()`, não faz
     * sentido usar o esquema versionado de getPages/getVisitorsByIp).
     */
    protected function visitsTotal(): int
    {
        return Cache::remember(
            'monitor:data:visits-total',
            now()->addSeconds((int) config('monitor.data_totals_cache_ttl_seconds', 45)),
            function () {
                try {
                    $expression = $this->jsonNumericSumExpression('visits');

                    return (int) (DB::table('monitors')->selectRaw("SUM({$expression}) as total")->value('total') ?? 0);
                } catch (QueryException $e) {
                    Log::warning('[laravel-monitor] falha ao calcular visits_total em getData. Erro original: '.$e->getMessage());

                    return 0;
                }
            }
        );
    }

    /**
     * `SUM` do tamanho de `data.sessions` por linha `Monitor`
     * (`JSON_LENGTH`/`json_array_length`, conforme o driver) — conta
     * quantas sessions foram registradas no total, **sem** dedupe entre
     * linhas (ao contrário de `uniqueIpsTotal()` abaixo, que dedupe de
     * verdade via `monitor_ip_stats`). Na prática cada session_id só
     * aparece numa única linha `Monitor` (um dispositivo reconhecido via
     * `remember_cookie` — ver `Monitor::newVisit()`, que já dedupe dentro
     * da própria linha antes de dar push), então esse número já reflete o
     * total de sessions distintas na imensa maioria dos casos; não é uma
     * garantia matemática de unicidade global só porque não existe uma
     * tabela `monitor_session_stats` dedicada (ao contrário de IPs, que já
     * tinham `monitor_ip_stats` de outra feature). Calculado 100% em SQL,
     * sem chunk: `JSON_LENGTH`/`json_array_length` operam por linha no
     * próprio SGBD, sem precisar trazer o JSON pra PHP.
     */
    protected function sessionsTotal(): int
    {
        return Cache::remember(
            'monitor:data:sessions-total',
            now()->addSeconds((int) config('monitor.data_totals_cache_ttl_seconds', 45)),
            function () {
                try {
                    $expression = $this->jsonArrayLengthExpression('sessions');

                    return (int) (DB::table('monitors')->selectRaw("SUM({$expression}) as total")->value('total') ?? 0);
                } catch (QueryException $e) {
                    Log::warning('[laravel-monitor] falha ao calcular sessions_total em getData. Erro original: '.$e->getMessage());

                    return 0;
                }
            }
        );
    }

    /**
     * Contagem de IPs únicos vistos por esta installation — reusa
     * `monitor_ip_stats` (1 linha por IP, já mantida por
     * `IpStat::recordVisit()` a cada request rastreada) em vez de dedupear
     * `data.ips` na unha: evita reinventar, dentro de `getData`, a mesma
     * agregação que motivou a criação daquela tabela em primeiro lugar
     * (ver README, "Per-IP stats"). `COUNT(*)` puro, sem risco de memória.
     * Fail-open: instalação ainda não migrada pra `0.8.0`+ (onde a tabela
     * foi introduzida) não pode quebrar getData.
     */
    protected function uniqueIpsTotal(): int
    {
        return Cache::remember(
            'monitor:data:unique-ips-total',
            now()->addSeconds((int) config('monitor.data_totals_cache_ttl_seconds', 45)),
            function () {
                try {
                    return (int) IpStat::count();
                } catch (QueryException $e) {
                    Log::warning('[laravel-monitor] tabela monitor_ip_stats não encontrada ao calcular unique_ips_total. Erro original: '.$e->getMessage());

                    return 0;
                }
            }
        );
    }

    /**
     * Expressão SQL portável (MySQL/SQLite — os dois drivers realmente
     * usados, ver `userIdColumn()`) pra somar um campo numérico escalar
     * dentro do blob `data`, com `CAST` pro tipo certo (sem o cast, o MySQL
     * soma a string extraída como 0 quando o path não existe em algumas
     * versões, e o SQLite trata o retorno de `json_extract` como REAL em
     * vez de INTEGER em certas comparações).
     */
    protected function jsonNumericSumExpression(string $field): string
    {
        return Monitor::query()->getConnection()->getDriverName() === 'mysql'
            ? "CAST(JSON_EXTRACT(data, '$.{$field}') AS UNSIGNED)"
            : "CAST(json_extract(data, '$.{$field}') AS INTEGER)";
    }

    /**
     * Expressão SQL portável pro tamanho de um array dentro do blob `data`.
     */
    protected function jsonArrayLengthExpression(string $field): string
    {
        return Monitor::query()->getConnection()->getDriverName() === 'mysql'
            ? "JSON_LENGTH(data, '$.{$field}')"
            : "json_array_length(data, '$.{$field}')";
    }

    /**
     * `SUM(counter)` de `monitor_block_results` — total de tentativas
     * bloqueadas (IP na blocklist OU path honeypot) já vistas por esta
     * installation. Cacheado com TTL curto e fixo
     * (`monitor.block_results_cache_ttl_seconds`), *não* o esquema
     * versionado de invalidatePagesCache/invalidateListingsCache: aquele
     * esquema assume mutação rara (ação manual de admin); este contador
     * incrementa a cada request bloqueada — um bot martelando um endpoint
     * flagado pode gerar centenas de incrementos por segundo, e bumpar
     * uma versão de cache compartilhada a cada uma delas junto invalidaria
     * (e recalcularia) getVisitorsByIp/getBlockedIps/etc pra todo mundo
     * sem necessidade nenhuma. Fail-open: tabela ainda não migrada não
     * pode quebrar getData.
     */
    protected function blockedAttemptsTotal(): int
    {
        return Cache::remember(
            'monitor:block-results:total',
            now()->addSeconds((int) config('monitor.block_results_cache_ttl_seconds', 45)),
            function () {
                try {
                    return (int) DB::table('monitor_block_results')->sum('counter');
                } catch (QueryException $e) {
                    Log::warning('[laravel-monitor] tabela monitor_block_results não encontrada ao calcular blocked_attempts_total. Erro original: '.$e->getMessage());

                    return 0;
                }
            }
        );
    }

    /**
     * Listagem paginada de `monitor_block_results` (`ip`, `counter`),
     * ordenada por `counter` desc — os IPs mais insistentes primeiro. Mesmo
     * auth de leitura de getVisitorsByIp/getBlockedIps (`local_token`
     * permanente ou o token efêmero de issueReadToken). Cache com o mesmo
     * TTL curto/fixo de `blockedAttemptsTotal()` (não o esquema versionado
     * — mesma justificativa: mutação a cada request bloqueada, não a cada
     * ação de admin). Fail-open: se a tabela ainda não existir, devolve
     * uma página vazia em vez de derrubar a action.
     */
    protected function getBlockResults(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $cacheKey = 'monitor:block-results:list:'.md5(json_encode([$page, $perPage]));
        $ttl = now()->addSeconds((int) config('monitor.block_results_cache_ttl_seconds', 45));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage) {
            try {
                $paginator = BlockResult::query()
                    ->orderByDesc('counter')
                    ->paginate($perPage, ['ip', 'counter'], 'page', $page);

                return [
                    'data' => $paginator->items(),
                    'meta' => [
                        'page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                    ],
                ];
            } catch (QueryException $e) {
                Log::warning('[laravel-monitor] tabela monitor_block_results não encontrada ao listar getBlockResults. Erro original: '.$e->getMessage());

                return [
                    'data' => [],
                    'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                ];
            }
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Enum de valores aceitos pelo parâmetro `filter` de getPages.
     * `pending_review` é o default quando `filter` não é enviado (ver
     * getPages) mas também pode ser pedido explicitamente.
     */
    protected const PAGES_FILTERS = ['all', '404', 'clean', 'blocked', 'pending_review', 'safe'];

    /**
     * Lista paginada/filtrável de paths visitados, agregando hits/estado
     * 404/blocked/status de revisão por path (chave `host/path`, mesmo
     * formato de `data.page`) — nunca manda as linhas `Monitor` cruas pro
     * cliente. Não agrega mais o sinal de scraper: era `data.flags.scraper`
     * do visitante subindo pro path (confuso — um path como `/` podia
     * aparecer marcado "possible scraper" só porque um bot passou por ele
     * uma vez). O sinal de scraper continua existindo normalmente, só que
     * fica restrito ao nível de IP/visitante (ver getVisitorsByIp).
     *
     * `date_from`/`date_to` filtram pela `updated_at` da linha `Monitor`
     * (não existe timestamp por página/hit no schema atual — cada linha
     * agrega várias páginas de um mesmo visitante — então isso é uma
     * aproximação: "atividade daquele visitante no período", não
     * "hit exato nesse path na data X").
     *
     * Sem `filter` explícito, o default é `pending_review` (não `all`):
     * a fila "ainda não analisado" (404 + não marcado como safe + não
     * bloqueado) vira a listagem padrão do dashboard, em vez do dump
     * completo. Um cliente que precise do dump completo continua podendo
     * pedir `filter=all` explicitamente.
     */
    protected function getPages(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $filter = (string) $request->input('filter', 'pending_review');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if (! in_array($filter, self::PAGES_FILTERS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid filter',
            ], 422);
        }

        $version = Cache::get('monitor:pages:version', 1);
        $cacheKey = 'monitor:pages:v'.$version.':'.md5(json_encode([
            $page, $perPage, $filter, $dateFrom, $dateTo,
        ]));
        $ttl = now()->addMinutes((int) config('monitor.pages_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage, $filter, $dateFrom, $dateTo) {
            return $this->buildPagesResult($page, $perPage, $filter, $dateFrom, $dateTo);
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Agrega `data.page`/`data.not_found` via `monitor_page_hits`
     * (laravel-monitor 103) — uma linha por (Monitor, path) mantida em
     * sincronia a cada save (`Monitor::booted()`), em vez de escanear e
     * decodificar o JSON de toda a tabela `Monitor` a cada `getPages`
     * (85s medidos em produção com 35.225 linhas — ver
     * bugs/laravel-monitor.md). `date_from`/`date_to` continuam
     * filtrando pelo `updated_at` do `Monitor` (mesma aproximação de
     * sempre — "atividade daquele visitante no período" — só que via
     * JOIN em SQL em vez de carregar a linha inteira em PHP).
     */
    protected function buildPagesResult(int $page, int $perPage, string $filter, ?string $dateFrom, ?string $dateTo): array
    {
        $query = DB::table('monitor_page_hits');

        if ($dateFrom || $dateTo) {
            $query->join('monitors', 'monitors.id', '=', 'monitor_page_hits.monitor_id');

            if ($dateFrom) {
                $query->where('monitors.updated_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->where('monitors.updated_at', '<=', $dateTo);
            }
        }

        $aggregated = [];

        $rows = $query
            ->selectRaw('monitor_page_hits.path as path, SUM(monitor_page_hits.hits) as hits, MAX(monitor_page_hits.not_found) as not_found')
            ->groupBy('monitor_page_hits.path')
            ->get();

        foreach ($rows as $row) {
            $aggregated[$row->path] = [
                'path' => $row->path,
                'hits' => (int) $row->hits,
                'not_found' => (bool) $row->not_found,
            ];
        }

        $blockedPaths = MonitorPath::where('status', 'trap')->pluck('path');
        // Só os paths marcados 'safe' importam pro match por sufixo (mesmo
        // padrão de $blockedPaths acima) — qualquer path sem linha em
        // monitor_paths é 'pending' por padrão, sem precisar de linha
        // nenhuma pra representar esse estado. 'trap'/'safe' são mutuamente
        // exclusivos (mesma linha, sobrescrita por flagScraperPath(s)/
        // markPathSafe(s) — ver comentário em flagScraperPath).
        $safePaths = MonitorPath::where('status', 'safe')->pluck('path');

        // Sets (não Collection::contains por closure) — a mesma checagem
        // de sufixo de pathMatches() reescrita como lookup O(1) por
        // sufixo (ver pathSuffixes()), em vez de comparar cada path
        // agregado contra as ~4.927 linhas trap+safe uma por uma
        // (O(paths_agregados × 4.927), o segundo gargalo medido nesta
        // rota — ver laravel-monitor 103). Resultado idêntico a
        // pathMatches(): equivalência provada no comentário de
        // pathSuffixes().
        $blockedSet = array_fill_keys($blockedPaths->map(fn ($p) => strtolower($p))->all(), true);
        $safeSet = array_fill_keys($safePaths->map(fn ($p) => strtolower($p))->all(), true);

        foreach ($aggregated as $path => &$row) {
            $suffixes = $this->pathSuffixes($path);

            $row['blocked'] = $this->matchesAnySuffix($suffixes, $blockedSet);
            // 'safe' só é um status válido pra um path que É 404 - o
            // marcador em monitor_paths casa por sufixo host-agnóstico, e
            // sem esse gate um path 'safe' num host onde é 404 vazava a
            // etiqueta pra outro host onde o mesmo sufixo é rota real
            // (nunca 404) - ver CHANGELOG [0.20.3].
            $row['status'] = ($row['not_found'] && $this->matchesAnySuffix($suffixes, $safeSet)) ? 'safe' : 'pending';
        }
        unset($row);

        $filtered = array_values(array_filter($aggregated, function ($row) use ($filter) {
            return match ($filter) {
                '404' => $row['not_found'],
                'clean' => ! $row['not_found'] && ! $row['blocked'],
                'blocked' => $row['blocked'],
                'pending_review' => $row['not_found'] && $row['status'] !== 'safe' && ! $row['blocked'],
                'safe' => $row['status'] === 'safe',
                default => true,
            };
        }));

        usort($filtered, fn ($a, $b) => $b['hits'] <=> $a['hits']);

        $total = count($filtered);
        $items = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Normaliza um path recebido em request (flagScraperPath(s)/
     * markPathSafe(s)/unflagPath/unmarkPathSafe): tira a barra inicial e
     * baixa a caixa. Minúsculo por escolha deliberada, não só formatação -
     * paths de scraper ("wp-admin/Install.php" vs "wp-admin/install.php")
     * não têm significado semântico na caixa, e sem essa normalização
     * `MonitorPath::updateOrCreate` grava a MESMA linha (a unique key do
     * MySQL é case-insensitive por padrão, `utf8mb4_unicode_ci`) só que às
     * vezes preservando uma caixa, às vezes outra, dependendo de qual
     * chegou primeiro - e o resto do código (buildPagesResult,
     * MonitorMethod::isPathBlocked) comparava em PHP puro, case-sensitive,
     * contra esse valor de caixa imprevisível. Resultado visto em produção
     * (cantagalo.it): um path flagado como trap (escrita OK, confirmada no
     * banco) continuava aparecendo como "pending" no dashboard porque a
     * linha salva estava numa caixa diferente da variante exibida
     * (relatado em cantagalo.it, 2026-09-09).
     */
    private function normalizePathInput(string $path): string
    {
        return strtolower(ltrim($path, '/'));
    }

    /**
     * Compara um path de `data.page` (chave "host/path", caixa exata da
     * visita real) contra um path normalizado de `monitor_paths`
     * (já minúsculo, via `normalizePathInput()`) - mesmo critério de
     * sempre (igualdade ou sufixo "/{$needle}", pra casar independente do
     * host), só que case-insensitive dos dois lados, pra não depender da
     * collation do banco (MySQL é case-insensitive por padrão pra
     * comparação SQL, mas SQLite/Postgres não são, e isto aqui é PHP puro,
     * não SQL).
     */
    private function pathMatches(string $haystack, string $needle): bool
    {
        $haystack = strtolower($haystack);
        $needle = strtolower($needle);

        return $haystack === $needle || str_ends_with($haystack, '/'.$needle);
    }

    /**
     * Usado só por `buildPagesResult()` (laravel-monitor 103) — mesmo
     * critério de `pathMatches()` acima, reescrito pra lookup O(1) em vez
     * de comparação por needle. Equivalência: `pathMatches($haystack,
     * $needle)` é verdadeiro sse `$needle === $haystack` OU `$haystack`
     * termina em `/{$needle}` — ou seja, sse `$needle` é exatamente
     * `$haystack` OU exatamente a parte de `$haystack` depois de alguma
     * ocorrência de `/`. Essa é, por definição, a lista de sufixos de
     * `$haystack` cortados em cada `/` (incluindo o próprio `$haystack`,
     * o caso de "nenhum corte"). Então "existe um needle em `$needles`
     * que casa com `$haystack`" é idêntico a "existe um sufixo de
     * `$haystack` que está em `$needles`" — e para path reais (poucos
     * `/`, tipicamente <10) isso é O(1) por sufixo contra um Set, em vez
     * de O(nº de needles) por needle contra o `$haystack` inteiro.
     */
    private function pathSuffixes(string $haystack): array
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

    /**
     * `$needleSet` é um Set (array_fill_keys, valores irrelevantes) de
     * needles já em minúsculo — ver pathSuffixes() pra equivalência com
     * pathMatches().
     */
    private function matchesAnySuffix(array $suffixes, array $needleSet): bool
    {
        foreach ($suffixes as $suffix) {
            if (isset($needleSet[$suffix])) {
                return true;
            }
        }

        return false;
    }

    /**
     * laravel-monitor 132: índice sufixo -> UMA chave "host/path" de
     * `monitor_page_hits` que casa (a primeira encontrada, `??=` —
     * suficiente pra checagem de existência via `isset()`, o valor em si
     * só serve de exemplo numa mensagem de erro). Mesma técnica de
     * `PathsAuditor::liveTrafficSuffixIndex()` (duplicada aqui por ser a
     * única classe fora de `PathsAuditor` que precisa disso), lida de
     * `monitor_page_hits` (mantida em sincronia por `Monitor::booted()`)
     * via uma única query agregada — nunca `Monitor::cursor()->each()`
     * decodificando o JSON de `data` de toda a tabela `Monitor`.
     *
     * Usado por `hasNotFoundEvidence()` (`$notFound = true` — evidência de
     * 404 pra `markPathSafe(s)`) e pelo guard de colisão com rota real de
     * `resolveScraperPathTargets()` (`$notFound = false` — path já
     * resolveu como página real em algum host).
     */
    private function pageHitsSuffixIndex(bool $notFound): array
    {
        $index = [];

        DB::table('monitor_page_hits')
            ->where('not_found', $notFound)
            ->distinct()
            ->pluck('path')
            ->each(function (string $path) use (&$index) {
                foreach ($this->pathSuffixes($path) as $suffix) {
                    $index[$suffix] ??= $path;
                }
            });

        return $index;
    }

    /**
     * Mesma ideia de `pageHitsSuffixIndex()`, mas guardando TODAS as
     * chaves que casam por sufixo (não só a primeira) — necessário quando,
     * ao contrário de uma checagem booleana, é preciso depois achar TODOS
     * os `monitor_id`s que geraram aquele hit (ver
     * `resolveScraperPathTargets()`, que usa isto com `$notFound = true`
     * pra achar os visitantes de um path flagado como trap).
     *
     * @return array<string, array<int, string>>
     */
    private function pageHitsSuffixIndexAll(bool $notFound): array
    {
        $index = [];

        DB::table('monitor_page_hits')
            ->where('not_found', $notFound)
            ->distinct()
            ->pluck('path')
            ->each(function (string $path) use (&$index) {
                foreach ($this->pathSuffixes($path) as $suffix) {
                    $index[$suffix][] = $path;
                }
            });

        return $index;
    }

    /**
     * Núcleo compartilhado de `flagScraperPath`/`flagScraperPaths`
     * (laravel-monitor 132): dado um lote de paths já normalizados,
     * devolve (a) quais têm colisão com rota real (mesmo guard da task
     * 101, `$path => $liveKey` que casou) e (b) pra cada path SEM colisão,
     * o Set de IPs que já visitaram esse path como 404 (`$path => [$ip =>
     * true]`), pra bloquear.
     *
     * Até esta task, tanto o singular quanto o lote faziam
     * `Monitor::cursor()->each()` decodificando `data.page`/`data.not_found`/
     * `data.ips` de TODA a tabela `Monitor` a cada chamada — mesma classe
     * de bug já eliminada do lado de leitura nas tasks 103/104/131, só que
     * nunca aplicada aqui. Agora: (1) o guard de colisão reusa
     * `pageHitsSuffixIndex(false)` (path já resolveu como página real em
     * algum host, mesmo índice que `PathsAuditor` já constrói pro check
     * "traffic"); (2) os paths SEM colisão são casados contra
     * `pageHitsSuffixIndexAll(true)` pra achar TODAS as chaves "host/path"
     * com evidência de 404; (3) só os `monitor_id`s donos dessas chaves
     * específicas (`monitor_page_hits`, indexado) são buscados — nunca a
     * tabela inteira; (4) só ENTÃO `Monitor::whereIn('id', $ids)` (um
     * SELECT pequeno, filtrado por PK) é lido, via `chunkById`, só pra
     * extrair `data.ips` desses hits específicos (`monitor_page_hits` não
     * guarda IP — ver migration `create_monitor_page_hits_table`).
     *
     * @param  array<int, string>  $paths  já normalizados (normalizePathInput)
     * @return array{0: array<string, string>, 1: array<string, array<string, bool>>}
     */
    private function resolveScraperPathTargets(array $paths): array
    {
        $liveIndex = $this->pageHitsSuffixIndex(false);
        $notFoundIndex = $this->pageHitsSuffixIndexAll(true);

        $liveRouteMatches = [];
        $matchedKeysByPath = [];

        foreach ($paths as $path) {
            if (isset($liveIndex[$path])) {
                $liveRouteMatches[$path] = $liveIndex[$path];

                continue;
            }

            $matchedKeysByPath[$path] = $notFoundIndex[$path] ?? [];
        }

        $allMatchedKeys = array_values(array_unique(array_merge([], ...array_values($matchedKeysByPath))));

        // path (chave "host/path") -> lista de monitor_id que geraram um
        // hit 404 nela. Uma query só, filtrada pelas chaves específicas já
        // encontradas acima (normalmente um punhado) — não um scan da
        // tabela inteira.
        $keyToMonitorIds = [];

        if (! empty($allMatchedKeys)) {
            DB::table('monitor_page_hits')
                ->whereIn('path', $allMatchedKeys)
                ->where('not_found', true)
                ->select('path', 'monitor_id')
                ->orderBy('monitor_id')
                ->chunk(1000, function ($rows) use (&$keyToMonitorIds) {
                    foreach ($rows as $row) {
                        $keyToMonitorIds[$row->path][] = $row->monitor_id;
                    }
                });
        }

        $monitorIdsNeeded = [];

        foreach ($matchedKeysByPath as $keys) {
            foreach ($keys as $key) {
                foreach ($keyToMonitorIds[$key] ?? [] as $id) {
                    $monitorIdsNeeded[$id] = true;
                }
            }
        }

        // monitor_id -> Set de IPs (data.ips) — só das linhas Monitor
        // realmente relevantes (achadas acima), via chunkById (mesmo teto
        // de memória previsível de pruneMonitors()), nunca ::all()/cursor()
        // sobre a tabela toda.
        $monitorIps = [];

        if (! empty($monitorIdsNeeded)) {
            Monitor::whereIn('id', array_keys($monitorIdsNeeded))
                ->select('id', 'data')
                ->chunkById(500, function ($monitors) use (&$monitorIps) {
                    foreach ($monitors as $monitor) {
                        foreach ((array) data_get($monitor, 'data.ips', []) as $ip) {
                            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                                $monitorIps[$monitor->id][$ip] = true;
                            }
                        }
                    }
                });
        }

        $ipsByPath = [];

        foreach ($matchedKeysByPath as $path => $keys) {
            $ips = [];

            foreach ($keys as $key) {
                foreach ($keyToMonitorIds[$key] ?? [] as $id) {
                    $ips += $monitorIps[$id] ?? [];
                }
            }

            $ipsByPath[$path] = $ips;
        }

        return [$liveRouteMatches, $ipsByPath];
    }

    /**
     * Incrementa o contador de versão lido por getPages — mutações
     * (flagScraperPath/unflagPath) invalidam todas as combinações de
     * parâmetros cacheadas de uma vez, já que os drivers array/file não
     * suportam Cache::tags().
     */
    protected function invalidatePagesCache(): void
    {
        if (! Cache::has('monitor:pages:version')) {
            Cache::forever('monitor:pages:version', 1);
        }

        Cache::increment('monitor:pages:version');
    }

    /**
     * Enum de valores aceitos pelo parâmetro `filter` de getVisitorsByIp.
     */
    protected const VISITORS_FILTERS = ['all', 'flagged', 'clean', 'blocked'];

    /**
     * Lista paginada/filtrável de visitantes por IP, a partir de
     * `monitor_ip_stats` (mantida via `IpStat::recordVisit` a cada
     * request) — não escaneia `Monitor.data.ips` cru.
     *
     * `date_from`/`date_to` filtram pelo `last_seen` da linha `IpStat`
     * (mesma aproximação documentada em getPages: "esse IP esteve ativo
     * nesse período", não um timestamp por visita).
     */
    protected function getVisitorsByIp(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $filter = (string) $request->input('filter', 'all');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if (! in_array($filter, self::VISITORS_FILTERS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid filter',
            ], 422);
        }

        $cacheKey = $this->listingsCacheKey('visitors', [
            $page, $perPage, $filter, $dateFrom, $dateTo,
        ]);
        $ttl = now()->addMinutes((int) config('monitor.listings_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage, $filter, $dateFrom, $dateTo) {
            return $this->buildVisitorsResult($page, $perPage, $filter, $dateFrom, $dateTo);
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Ao contrário de getPages (que agrega um blob JSON por linha
     * `Monitor` e por isso precisa carregar tudo em PHP),
     * `monitor_ip_stats` já é uma linha por IP — filtro/ordenação/
     * paginação são feitos direto em SQL. `blocked` (join lógico contra
     * `monitor_blocked_ips`) usa `whereIn`/`whereNotIn` com a lista de
     * IPs bloqueados em vez de um JOIN de verdade, pra manter o dataset
     * de IPs bloqueados (normalmente pequeno) resolvido numa query só.
     *
     * Desde a task 80: `flagged`/`flagged_signals` continuam refletindo só
     * o request mais recente daquele IP (ver IpStat::recordVisit) — não
     * cumulativo, pode voltar a `true` no próximo request mesmo depois de
     * um humano revisar o IP. Desde a task 91: `filter=flagged` também
     * exclui IPs já em `monitor_blocked_ips` — um IP bloqueado já foi
     * confirmado como scraper, não precisa reaparecer na fila de
     * "possível". Desde a task 136: não existe mais `safe` de IP (era uma
     * whitelist permanente baseada só num IP — identidade instável demais
     * pra isso, ver CHANGELOG `[0.29.0]`) — quem quiser descartar um
     * candidato da fila de revisão simplesmente ignora, sem ação
     * dedicada; `flagged` continua existindo pra quem quiser garimpar.
     */
    protected function buildVisitorsResult(int $page, int $perPage, string $filter, ?string $dateFrom, ?string $dateTo): array
    {
        $query = IpStat::query();

        if ($dateFrom) {
            $query->where('last_seen', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('last_seen', '<=', $dateTo);
        }

        $blockedIps = BlockedIp::pluck('ip');

        match ($filter) {
            // whereNotIn($blockedIps): um IP já bloqueado já foi
            // confirmado como scraper, não deve reaparecer na fila de
            // "possível" (task 91).
            'flagged' => $query->where('flagged', true)->whereNotIn('ip', $blockedIps),
            'clean' => $query->where('flagged', false)->whereNotIn('ip', $blockedIps),
            'blocked' => $query->whereIn('ip', $blockedIps),
            default => null,
        };

        $paginator = $query
            // flagged=true primeiro (fila de revisão real), resto depois
            // — dentro de cada grupo, desempata por visit_count desc,
            // igual antes da task 80. CASE WHEN em vez de orderByRaw por
            // coluna booleana: nem MySQL nem SQLite deixam ordenar direto
            // por uma expressão booleana sem isso.
            ->orderByRaw('CASE WHEN flagged = 1 THEN 0 ELSE 1 END')
            ->orderByDesc('visit_count')
            ->paginate($perPage, ['ip', 'visit_count', 'first_seen', 'last_seen', 'flagged', 'flagged_signals'], 'page', $page);

        $items = collect($paginator->items())->map(function (IpStat $stat) use ($blockedIps) {
            return [
                'ip' => $stat->ip,
                'visit_count' => $stat->visit_count,
                'first_seen' => optional($stat->first_seen)->toIso8601String(),
                'last_seen' => optional($stat->last_seen)->toIso8601String(),
                'flagged' => $stat->flagged,
                'flagged_signals' => $stat->flagged_signals,
                'blocked' => $blockedIps->contains($stat->ip),
            ];
        })->values();

        return [
            'data' => $items,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Dado um IP, agrega os paths (`data.page`) de todos os `Monitor` que
     * já viram esse IP (`data.ips` contém o IP) — serve pra confirmar
     * visualmente que um IP é scraper antes de bloquear. Ao contrário de
     * `flagScraperPath` (que dado um path escaneia todos os `Monitor` e
     * casa por sufixo, já que o mesmo path pode aparecer sob hosts
     * diferentes), aqui o match é direto (IP não tem variação de host).
     *
     * Até a laravel-monitor 104 isto era um scan em chunks decodificando
     * `data` de TODA a tabela `Monitor` a cada chamada (mesma classe de
     * bug de `buildPagesResult()` antes da 103, ver bugs/laravel-monitor.md)
     * — o custo de achar quais linhas tinham o IP era proporcional ao
     * tamanho total de `Monitor`, não ao número de visitas daquele IP.
     * Agora usa as tabelas relacionais já mantidas por `Monitor::booted()`
     * (`monitor_visit_ips` pra achar os `monitor_id` por IP via índice,
     * `monitor_page_hits`, da 103, pra somar hits por path só desses
     * ids) — os dois SUMs/JOINs ficam em SQL, sem decodificar JSON em
     * PHP. Sem paginação/cache: dataset pequeno por IP, chamada sob
     * demanda ao expandir uma linha no dashboard, não em toda carga de
     * página.
     */
    protected function getVisitorPaths(Request $request)
    {
        $ip = (string) $request->input('ip', '');

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IP provided',
            ], 422);
        }

        $monitorIds = DB::table('monitor_visit_ips')->where('ip', $ip)->pluck('monitor_id');

        $paths = $monitorIds->isEmpty()
            ? collect()
            : DB::table('monitor_page_hits')
                ->whereIn('monitor_id', $monitorIds)
                ->selectRaw('path, SUM(hits) as hits')
                ->groupBy('path')
                ->orderByDesc('hits')
                ->get()
                ->map(fn ($row) => ['path' => $row->path, 'hits' => (int) $row->hits])
                ->values();

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'paths' => $paths,
        ]);
    }

    /**
     * Listagem paginada de `monitor_blocked_ips` (sem filtro além de
     * paginação — dataset pequeno, mas pagina em SQL real já que não há
     * blob JSON pra agregar aqui, ao contrário de getPages/getVisitorsByIp).
     */
    protected function getBlockedIps(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $cacheKey = $this->listingsCacheKey('blocked-ips', [$page, $perPage]);
        $ttl = now()->addMinutes((int) config('monitor.listings_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage) {
            $paginator = BlockedIp::query()
                ->orderByDesc('created_at')
                ->paginate($perPage, ['ip', 'source', 'created_at'], 'page', $page);

            return [
                'data' => $paginator->items(),
                'meta' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ];
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Listagem paginada dos paths com `status = 'trap'` em `monitor_paths`
     * — mesmo padrão de getBlockedIps, mesmo response shape de antes da
     * fusão em `monitor_paths` (`{"path", "created_at"}`).
     */
    protected function getBlockedPaths(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $cacheKey = $this->listingsCacheKey('blocked-paths', [$page, $perPage]);
        $ttl = now()->addMinutes((int) config('monitor.listings_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage) {
            $paginator = MonitorPath::where('status', 'trap')
                ->orderByDesc('created_at')
                ->paginate($perPage, ['path', 'created_at'], 'page', $page);

            return [
                'data' => $paginator->items(),
                'meta' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ];
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Nome da coluna usada para agrupar/filtrar `Monitor` por `user_id`:
     * a coluna gerada indexada (`monitors_user_id`, task 40) em MySQL, ou
     * a expressão JSON crua (`data->user_id`) nos demais drivers — mesma
     * escolha que `Monitor::scopeForUserId()` já faz, aqui exposta pra
     * ser usada num `groupBy`/`select` em vez de um `where` de igualdade.
     */
    protected function userIdColumn(): string
    {
        return Monitor::query()->getConnection()->getDriverName() === 'mysql'
            ? 'monitors_user_id'
            : 'data->user_id';
    }

    /**
     * Listagem agregada de visitantes por `user_id` (CRM), paginada —
     * um dashboard vendo "quem são meus usuários autenticados e quando
     * foi a última atividade de cada um", sem escanear `data` em PHP:
     * agrega `SUM(data.visits)`/`MAX(updated_at)` direto em SQL, agrupando
     * pela coluna gerada indexada (`monitors_user_id`, task 40) em MySQL
     * — nunca `where('data->user_id', ...)` cru, que não usa o índice
     * (ver `Monitor::scopeForUserId()`).
     *
     * Desde a task 92: `visits_count` soma `data.visits` (via
     * `jsonNumericSumExpression()`, mesma expressão já usada por
     * `visitsTotal()` em getData) em vez de `COUNT(*)` de linhas
     * `Monitor` — uma linha `Monitor` é reaproveitada por dispositivo/
     * navegador (reconectado via remember-me entre sessões, ver
     * `SessionVisitorTracker::track()`), não criada por visita, então
     * `COUNT(*)` sempre dava 1 pra um usuário que só troca de sessão no
     * mesmo navegador. `SUM` ignora linhas sem a chave `visits` (dados
     * antigos, de antes desta task); por isso o `?? 0` no map abaixo.
     *
     * `name`/`email`: como não existe coluna própria pra isso (só
     * aparecem dentro do blob `data` quando o app hospedeiro chamou
     * `Monitor::tag(['name' => .., 'email' => ..])`), são resolvidos com
     * uma consulta extra por usuário da página atual (no máximo
     * `per_page` linhas), pegando a linha mais recente
     * (`forUserId($id)->orderByDesc('updated_at')->first()`) — nunca um
     * lookup contra nenhuma tabela `users`.
     */
    protected function getUsers(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        $cacheKey = $this->listingsCacheKey('users', [$page, $perPage]);
        $ttl = now()->addMinutes((int) config('monitor.listings_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage) {
            return $this->buildUsersResult($page, $perPage);
        });

        return response()->json(['success' => true] + $result);
    }

    protected function buildUsersResult(int $page, int $perPage): array
    {
        $column = $this->userIdColumn();
        $visitsExpression = $this->jsonNumericSumExpression('visits');

        $paginator = Monitor::query()
            ->whereNotNull($column)
            ->select("{$column} as user_id")
            ->selectRaw("SUM({$visitsExpression}) as visits_count")
            ->selectRaw('MAX(updated_at) as last_activity')
            ->groupBy($column)
            ->orderByDesc('last_activity')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($row) {
            // orderByDesc('updated_at') sozinho empata quando 2+ linhas do
            // mesmo usuário são gravadas dentro do mesmo segundo (comum em
            // testes, e possível em produção sob concorrência) - sem um
            // desempate determinístico, o SGBD podia devolver a linha mais
            // ANTIGA das empatadas, fazendo name/email sumir mesmo com um
            // Monitor::tag() recente. 'id' cresce com a inserção, então
            // desempata pela ordem real de criação.
            $latest = Monitor::forUserId($row->user_id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->select('data')
                ->first();

            return [
                'user_id' => $row->user_id,
                'visits_count' => (int) $row->visits_count,
                'last_activity' => $row->last_activity
                    ? Carbon::parse($row->last_activity)->toIso8601String()
                    : null,
                'name' => $latest ? data_get($latest, 'data.name') : null,
                'email' => $latest ? data_get($latest, 'data.email') : null,
            ];
        })->values();

        return [
            'data' => $items,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Detalhe de um `user_id`: as linhas `Monitor` (dispositivos/
     * navegadores) já taggeadas com esse usuário, paginado — dados que já
     * existem em `data` de cada linha (páginas, IPs, timestamps), nada
     * novo capturado/rastreado por esta action.
     */
    protected function getUserVisits(Request $request)
    {
        $rawUserId = $request->input('user_id');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        if ($rawUserId === null || $rawUserId === '') {
            return response()->json([
                'success' => false,
                'message' => 'user_id is required',
            ], 422);
        }

        // Cast pra int: user_id sempre vem de Auth::id() (int) do lado de
        // quem gravou, mas chega aqui como string (query param HTTP).
        // Fora do MySQL, Monitor::scopeForUserId() NÃO faz esse cast
        // internamente (json_extract do SQLite devolve o tipo nativo, e
        // '3' string nunca bate com 3 inteiro lá) - repassar a string crua
        // faria getUserVisits sempre devolver 0 linhas em qualquer host
        // que não seja MySQL.
        $userId = (int) $rawUserId;

        $cacheKey = $this->listingsCacheKey('user-visits', [$userId, $page, $perPage]);
        $ttl = now()->addMinutes((int) config('monitor.listings_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($userId, $page, $perPage) {
            $paginator = Monitor::forUserId($userId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate($perPage, ['id', 'data', 'created_at', 'updated_at'], 'page', $page);

            return [
                'data' => $paginator->items(),
                'meta' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ];
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Chave de cache versionada compartilhada por getVisitorsByIp/
     * getBlockedIps/getBlockedPaths — mesmo esquema de getPages, mas com
     * contador de versão próprio (monitor:listings:version) pra não
     * mexer no cache já lançado de getPages.
     */
    protected function listingsCacheKey(string $prefix, array $params): string
    {
        return ListingsCache::key($prefix, $params);
    }

    /**
     * Incrementa o contador de versão lido por getVisitorsByIp/
     * getBlockedIps/getBlockedPaths — chamado por updateBlockedIps/
     * unblockIp/flagScraperPath/unflagPath (e, desde a task 133, por
     * ScraperBlocker::registerOffense no caminho automático), já que
     * todas mudam o estado de bloqueio refletido nessas listagens.
     * Delega pra Support\ListingsCache (task 133) - extraído pra lá pra
     * ficar acessível fora do controller.
     */
    protected function invalidateListingsCache(): void
    {
        ListingsCache::invalidate();
    }

    /**
     * Regenera o arquivo de deny-list sob demanda (ver
     * `monitor:export-denylist`, equivalente via artisan). Diferente do
     * antigo `maybeAutoExportDenylist()` (removido - opt-in automático a
     * cada updateBlockedIps/unblockIp/flagScraperPath, reescrevia o
     * arquivo inteiro a cada request), esta action só roda quando o
     * consumidor do pacote pede explicitamente (botão no dashboard, cron
     * próprio via API, etc) - controle explícito de quando exportar, em
     * vez de uma flag que dispara sozinha. Exige `local_token` (não entra
     * em `$isValidReadToken` no dispatcher) por disparar escrita em disco
     * no servidor do cliente.
     */
    protected function exportDenylist(Request $request)
    {
        try {
            $path = DenylistExporter::writeToDisk(config('monitor.denylist_format', 'apache'));
        } catch (\Throwable $e) {
            Log::error('Monitor denylist export failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to write denylist file',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'path' => $path,
        ]);
    }

    /**
     * Auditoria manual/sob-demanda (nunca automática, task 102) — mesmo
     * `PathsAuditor::audit()` do comando `monitor:audit-paths`. Report-only:
     * não desfaz nada sozinho, a ação de corrigir continua manual via
     * `unflagPath`/`unmarkPathSafe` (o consumidor decide olhando o
     * relatório).
     */
    protected function auditPaths(Request $request)
    {
        return response()->json([
            'success' => true,
            'findings' => PathsAuditor::audit(),
        ]);
    }

    protected function clearData(Request $request)
    {
        // futuramente: validação/admin check
        Monitor::truncate();

        return response()->json([
            'success' => true,
            'message' => 'All monitor data cleared',
        ]);
    }

    /**
     * Cleanup parcial, complementar ao truncate total de clearData:
     * apaga só linhas de `Monitor`/`monitor_ip_stats` mais antigas que
     * `older_than_days`, opcionalmente restrito aos IPs confirmado-
     * bloqueados (`only_blocked`, ver `monitor_blocked_ips`).
     *
     * Antes da task 81, este filtro usava o sinal *automático* da
     * heurística (`IpStat.flagged`/`Monitor.data.flags.scraper`) — não
     * cumulativo, reflete só a última requisição daquele IP, sem nenhuma
     * revisão humana — pra decidir o que apagar permanentemente. Trocado
     * pra `monitor_blocked_ips` (IP de fato confirmado/bloqueado pelo
     * usuário), evitando deleção de dado em cima de um falso positivo não
     * revisado. Parâmetro renomeado de `only_scraper_flagged` pra
     * `only_blocked` (reflete a nova semântica).
     *
     * Lógica de fato (chunked/indexado, invalidação de cache) delegada a
     * `Support\DataPruner` (task 134) — reusada também pelo comando
     * `monitor:prune`, sem mudar comportamento/response desta rota.
     */
    protected function pruneData(Request $request)
    {
        $olderThanDays = $request->input('older_than_days');
        $onlyBlocked = filter_var($request->input('only_blocked', false), FILTER_VALIDATE_BOOLEAN);

        if (! is_numeric($olderThanDays) || (int) $olderThanDays < 0) {
            return response()->json([
                'success' => false,
                'message' => 'older_than_days is required and must be a non-negative integer',
            ], 422);
        }

        $result = DataPruner::prune((int) $olderThanDays, $onlyBlocked);

        return response()->json([
            'success' => true,
            'monitors_deleted' => $result['monitors_deleted'],
            'ip_stats_deleted' => $result['ip_stats_deleted'],
        ]);
    }

    /**
     * Bloqueia uma lista de IPs. Desde a task 136: passa pelo MESMO
     * mecanismo escalonado do bloqueio automático
     * (`ScraperBlocker::registerOffense`, source `'manual'`) em vez de
     * criar um `BlockedIp` permanente direto — unifica reputação manual +
     * automática numa escada só: reincidência (automática OU manual,
     * depois de um bloqueio anterior expirado) soma pro mesmo
     * `lifetime_offense_count`, até virar permanente
     * (`auto_block_permanent_after_lifetime_offenses`). `registerOffense`
     * já cuida de invalidar o cache de bloqueio (`Cache::forget`) e o
     * `ListingsCache` por IP, então não precisa repetir aqui.
     */
    protected function updateBlockedIps(Request $request)
    {
        $ips = $request->input('ips', []);

        if (! is_array($ips) || empty($ips)) {
            return response()->json([
                'success' => false,
                'message' => 'No IPs provided',
            ], 422);
        }

        $blocked = [];
        $scraperBlocker = new ScraperBlocker;

        foreach ($ips as $ip) {
            if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            $scraperBlocker->registerOffense($ip, 'manual');
            $blocked[] = $ip;
        }

        if (empty($blocked)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IPs provided',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'blocked' => $blocked,
        ]);
    }

    /**
     * Reverte `updateBlockedIps`/`flagScraperPath` para um IP: remove de
     * `monitor_blocked_ips` e limpa o cache lido por
     * `MonitorMethod::isBlocked()`.
     */
    protected function unblockIp(Request $request)
    {
        $ip = (string) $request->input('ip', '');

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IP provided',
            ], 422);
        }

        $removed = BlockedIp::where('ip', $ip)->delete() > 0;
        Cache::forget("monitor:blocked-ip:{$ip}");
        $this->invalidateListingsCache();

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'was_blocked' => $removed,
        ]);
    }

    /**
     * Flaga um path (sem host - a parte "variável" da URL, ex:
     * "wp-admin/install.php") como scrapper. Duas coisas acontecem: (1) a
     * linha desse path em `monitor_paths` vira `status = 'trap'`
     * (`updateOrCreate`, não `firstOrCreate` — sobrescreve um `'safe'`
     * anterior, tornando flag/markSafe mutuamente exclusivos já que é a
     * mesma linha/tabela desde a fusão que uniu `monitor_blocked_paths` e
     * `monitor_path_reviews`), checada por `MonitorMethod` pra bloquear
     * (403) qualquer request futura àquele path, em qualquer host que esta
     * installation atenda; (2) os IPs que já visitaram esse path (via
     * `data.page` dos registros de Monitor) são bloqueados em
     * `monitor_blocked_ips`, mesmo mecanismo de `updateBlockedIps`.
     *
     * Até a laravel-monitor 132 isto era `Monitor::cursor()->each()`
     * decodificando `data.page`/`data.not_found`/`data.ips` de TODA a
     * tabela `Monitor` a cada chamada — mesmo memory exhaustion do task 88
     * (`getData`) evitado via `cursor()` em vez de `::all()`, mas ainda
     * O(linhas de `Monitor`) em tempo, não em memória (confirmado
     * estourando o timeout de 30s do home-page em produção — cantagalo.it,
     * installation id=3, 41.452 linhas em `monitors`, ver
     * `historico/laravel-monitor.md`). Agora delega pra
     * `resolveScraperPathTargets()` (guard de colisão + IPs a bloquear via
     * `monitor_page_hits`, sem decodificar JSON de `Monitor` fora dos
     * `monitor_id`s realmente envolvidos).
     */
    protected function flagScraperPath(Request $request)
    {
        $path = $this->normalizePathInput((string) $request->input('path', ''));

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        [$liveRouteMatches, $ipsByPath] = $this->resolveScraperPathTargets([$path]);

        if (isset($liveRouteMatches[$path])) {
            return response()->json([
                'success' => false,
                'message' => "\"{$path}\" also resolves as a live route at \"{$liveRouteMatches[$path]}\" — refusing to flag it as a trap",
            ], 422);
        }

        MonitorPath::updateOrCreate(['path' => $path], ['status' => 'trap']);
        Cache::forget("monitor:blocked-path:{$path}");
        $this->invalidatePagesCache();
        $this->invalidateListingsCache();

        $blockedIps = [];

        foreach (array_keys($ipsByPath[$path] ?? []) as $ip) {
            // laravel-monitor 96: honeypot é o sinal de maior confiança do
            // auto-block (um hit já basta, sem contagem de sinais) - segue
            // a mesma escada temporária/escalonada do resto do auto-block
            // em vez de virar permanente e estático desde o primeiro hit.
            // Ver ScraperBlocker::registerOffense.
            (new ScraperBlocker)->registerOffense($ip, 'scraper-path');
            $blockedIps[$ip] = true;
        }

        return response()->json([
            'success' => true,
            'path' => $path,
            'blocked_ips' => array_keys($blockedIps),
        ]);
    }

    /**
     * Versão em lote de `flagScraperPath`, mesmo padrão de
     * `updateBlockedIps` (array de paths, best-effort - ignora entradas
     * inválidas em vez de falhar tudo, invalida cache uma vez só no
     * final). Existe pra evitar N requests HTTP separadas (uma por path)
     * quando dezenas/centenas de paths precisam ser flagados de uma vez
     * (botão "Flag selected as trap" no dashboard, e
     * TriageMonitorPathsJob no home-page) - uma rajada dessas via
     * `flagScraperPath` singular, uma chamada por path, é ruim pro
     * servidor do lado de cá (rate limit/WAF genérico) sem ganho nenhum.
     * Diferença de eficiência real vs. chamar o singular em loop: a
     * varredura pra achar IPs que visitaram os paths acontece UMA VEZ pra
     * todo o lote aqui, não uma vez por path.
     *
     * Até a laravel-monitor 132 isto era `Monitor::cursor()->each()` sobre
     * toda a tabela `Monitor` a cada chamada (mesmo motivo do comentário
     * em `flagScraperPath()` (singular), só que aqui o lote (dezenas/
     * centenas de paths do botão "Flag selected as trap" ou do
     * `TriageMonitorPathsJob`) tornava o custo ainda mais crítico. Agora
     * delega pra `resolveScraperPathTargets()`, chamada uma vez só com o
     * lote inteiro (não um loop chamando-a por path).
     */
    protected function flagScraperPaths(Request $request)
    {
        $paths = $request->input('paths', []);

        if (! is_array($paths) || empty($paths)) {
            return response()->json([
                'success' => false,
                'message' => 'No paths provided',
            ], 422);
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $path = $this->normalizePathInput($path);

            if ($path === '') {
                continue;
            }

            $normalized[] = $path;
        }

        $normalized = array_values(array_unique($normalized));

        if (empty($normalized)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid paths provided',
            ], 422);
        }

        [$liveRouteMatches, $ipsByPath] = $this->resolveScraperPathTargets($normalized);

        $flagged = [];
        $rejected = [];
        $blockedIps = [];

        foreach ($normalized as $path) {
            if (isset($liveRouteMatches[$path])) {
                $rejected[] = [
                    'path' => $path,
                    'reason' => "\"{$path}\" also resolves as a live route at \"{$liveRouteMatches[$path]}\"",
                ];

                continue;
            }

            MonitorPath::updateOrCreate(['path' => $path], ['status' => 'trap']);
            Cache::forget("monitor:blocked-path:{$path}");
            $flagged[] = $path;

            foreach (array_keys($ipsByPath[$path] ?? []) as $ip) {
                (new ScraperBlocker)->registerOffense($ip, 'scraper-path');
                $blockedIps[$ip] = true;
            }
        }

        if (empty($flagged)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid paths provided',
                'rejected' => $rejected,
            ], 422);
        }

        $this->invalidatePagesCache();
        $this->invalidateListingsCache();

        return response()->json([
            'success' => true,
            'paths' => $flagged,
            'rejected' => $rejected,
            'blocked_ips' => array_keys($blockedIps),
        ]);
    }

    /**
     * Reverte `flagScraperPath` para um path: remove a linha `status =
     * 'trap'` correspondente em `monitor_paths` e limpa o cache lido por
     * `MonitorMethod::isPathBlocked()`. Não desbloqueia os IPs que
     * `flagScraperPath` bloqueou por causa desse path — isso é feito
     * separadamente via `unblockIp`. Scoped a `status = 'trap'` (não um
     * delete cego por `path`): defensivo contra apagar por engano uma
     * linha `'safe'` do mesmo path — na prática nunca deveria haver as
     * duas ao mesmo tempo, já que flag/markSafe são mutuamente exclusivos
     * na mesma linha desde a fusão em `monitor_paths`.
     */
    protected function unflagPath(Request $request)
    {
        $path = $this->normalizePathInput((string) $request->input('path', ''));

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        $removed = MonitorPath::where('path', $path)->where('status', 'trap')->delete() > 0;
        Cache::forget("monitor:blocked-path:{$path}");
        $this->invalidatePagesCache();
        $this->invalidateListingsCache();

        return response()->json([
            'success' => true,
            'path' => $path,
            'was_flagged' => $removed,
        ]);
    }

    /**
     * Marca um path (sem host, mesmo formato de flagScraperPath) como
     * `safe`, tirando-o da fila `pending_review` de getPages — usada
     * quando quem revisa confirma que um 404 recorrente não é scraper
     * (ex: link antigo removido do site, sem nenhuma malícia). Não tem
     * nenhum efeito de bloqueio (diferente de flagScraperPath); é só o
     * status exposto por getPages/buildPagesResult. `updateOrCreate`
     * sobrescreve um `'trap'` anterior na mesma linha (mutuamente
     * exclusivo com flagScraperPath desde a fusão em `monitor_paths`).
     */
    protected function markPathSafe(Request $request)
    {
        $path = $this->normalizePathInput((string) $request->input('path', ''));

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        // laravel-monitor 101: simétrico à guarda de flagScraperPath - só
        // marca 'safe' se existir pelo menos 1 hit com not_found=true em
        // algum host pra esse path. Sem isso, marcar 'safe' não protege
        // nada: é dado órfão desde o início (mesma origem do "login" da
        // task 100).
        if (! $this->hasNotFoundEvidence($path)) {
            return response()->json([
                'success' => false,
                'message' => "\"{$path}\" has no recorded 404 — marking it safe would not protect anything",
            ], 422);
        }

        MonitorPath::updateOrCreate(
            ['path' => $path],
            ['status' => 'safe', 'reviewed_at' => now()]
        );

        $this->invalidatePagesCache();

        return response()->json([
            'success' => true,
            'path' => $path,
            'status' => 'safe',
        ]);
    }

    /**
     * Suporte de `markPathSafe()`: existe pelo menos um hit 404 registrado
     * (`monitor_page_hits.not_found = true`) pra alguma chave "host/path"
     * que bate por sufixo (`pathMatches()`) contra `$path`?
     *
     * Até a laravel-monitor 132 isto era `Monitor::cursor()` decodificando
     * `data.not_found` de TODA a tabela `Monitor` a cada chamada — mesma
     * classe de bug do lado de escrita já corrigida do lado de leitura nas
     * tasks 103/104/131 (confirmado estourando o timeout de 30s do
     * home-page em produção — cantagalo.it, installation id=3, 41.452
     * linhas em `monitors`). Agora lê de `monitor_page_hits` (mantida em
     * sincronia por `Monitor::booted()`) via `pageHitsSuffixIndex(true)` —
     * mesma técnica de `PathsAuditor::liveTrafficSuffixIndex()`.
     */
    private function hasNotFoundEvidence(string $path): bool
    {
        return isset($this->pageHitsSuffixIndex(true)[$path]);
    }

    /**
     * Versão em lote de `markPathSafe`, mesmo padrão de `flagScraperPaths`/
     * `updateBlockedIps` acima (array, best-effort, sem efeito colateral
     * de bloqueio) - usada pelo botão "Mark selected as safe" (que hoje
     * fazia uma chamada por path via `Promise.all` no JS do dashboard) e
     * por `TriageMonitorPathsJob`.
     *
     * Até a laravel-monitor 132 a guarda por lote fazia uma única passada
     * por `Monitor::cursor()` checando todos os paths pendentes de uma vez
     * (saindo cedo só quando TODOS já tinham evidência) — ainda assim
     * O(linhas de `Monitor`) no pior caso (lote grande, evidência só
     * aparecendo nas últimas linhas), mesma classe de bug do singular.
     * Agora reusa `pageHitsSuffixIndex(true)` (uma query agregada, não por
     * path do lote) do mesmo jeito que `hasNotFoundEvidence()` usa pro
     * singular.
     */
    protected function markPathsSafe(Request $request)
    {
        $paths = $request->input('paths', []);

        if (! is_array($paths) || empty($paths)) {
            return response()->json([
                'success' => false,
                'message' => 'No paths provided',
            ], 422);
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $path = $this->normalizePathInput($path);

            if ($path === '') {
                continue;
            }

            $normalized[] = $path;
        }

        $normalized = array_values(array_unique($normalized));

        if (empty($normalized)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid paths provided',
            ], 422);
        }

        $withEvidence = $this->pageHitsSuffixIndex(true);

        $marked = [];
        $rejected = [];

        foreach ($normalized as $path) {
            if (! isset($withEvidence[$path])) {
                $rejected[] = [
                    'path' => $path,
                    'reason' => "\"{$path}\" has no recorded 404 — marking it safe would not protect anything",
                ];

                continue;
            }

            MonitorPath::updateOrCreate(
                ['path' => $path],
                ['status' => 'safe', 'reviewed_at' => now()]
            );

            $marked[] = $path;
        }

        if (empty($marked)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid paths provided',
                'rejected' => $rejected,
            ], 422);
        }

        $this->invalidatePagesCache();

        return response()->json([
            'success' => true,
            'paths' => $marked,
            'rejected' => $rejected,
        ]);
    }

    /**
     * Reverte markPathSafe: apaga a linha `status = 'safe'` correspondente
     * em `monitor_paths`, e o path volta a ser 'pending' por padrão (mesma
     * linha de raciocínio de unblockIp/unflagPath removendo em vez de
     * gravar um segundo estado explícito).
     */
    protected function unmarkPathSafe(Request $request)
    {
        $path = $this->normalizePathInput((string) $request->input('path', ''));

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        $removed = MonitorPath::where('path', $path)->where('status', 'safe')->delete() > 0;
        $this->invalidatePagesCache();

        return response()->json([
            'success' => true,
            'path' => $path,
            'was_safe' => $removed,
        ]);
    }

    protected function updateRules(Request $request)
    {
        // implementar depois
        return response()->json([
            'success' => true,
            'message' => 'Monitoring rules updated (stub)',
        ]);
    }
}
