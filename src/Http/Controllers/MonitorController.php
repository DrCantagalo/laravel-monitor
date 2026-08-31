<?php

namespace Drcantagalo\LaravelMonitor\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\BlockedPath;
use Drcantagalo\LaravelMonitor\Models\BlockResult;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\PathReview;
use Drcantagalo\LaravelMonitor\Support\DenylistExporter;

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

            case 'markIpSafe':
                return $this->markIpSafe($request);

            case 'unmarkIpSafe':
                return $this->unmarkIpSafe($request);

            case 'flagScraperPath':
                return $this->flagScraperPath($request);

            case 'unflagPath':
                return $this->unflagPath($request);

            case 'markPathSafe':
                return $this->markPathSafe($request);

            case 'unmarkPathSafe':
                return $this->unmarkPathSafe($request);

            case 'updateRules':
                return $this->updateRules($request);

            case 'issueReadToken':
                return $this->issueReadToken($request);

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid action'
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

    protected function getData(Request $request)
    {
        $data = Monitor::all(); // futuramente adicionar filtros, período etc.

        return response()->json([
            'success' => true,
            'data' => $data,
            // Task 83: soma agregada de monitor_block_results, pro
            // dashboard reusar o mesmo fetch que já alimenta os cards de
            // KPI em vez de bater um endpoint novo só pra esse número.
            'blocked_attempts_total' => $this->blockedAttemptsTotal(),
        ]);
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
                    Log::warning('[laravel-monitor] tabela monitor_block_results não encontrada ao calcular blocked_attempts_total. Erro original: ' . $e->getMessage());

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

        $cacheKey = 'monitor:block-results:list:' . md5(json_encode([$page, $perPage]));
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
                Log::warning('[laravel-monitor] tabela monitor_block_results não encontrada ao listar getBlockResults. Erro original: ' . $e->getMessage());

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
    protected const PAGES_FILTERS = ['all', '404', 'clean', 'blocked', 'pending_review'];

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
        $cacheKey = 'monitor:pages:v' . $version . ':' . md5(json_encode([
            $page, $perPage, $filter, $dateFrom, $dateTo,
        ]));
        $ttl = now()->addMinutes((int) config('monitor.pages_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage, $filter, $dateFrom, $dateTo) {
            return $this->buildPagesResult($page, $perPage, $filter, $dateFrom, $dateTo);
        });

        return response()->json(['success' => true] + $result);
    }

    /**
     * Agrega `data.page`/`data.not_found` de todas
     * as linhas `Monitor` (opcionalmente restritas por `updated_at`) num
     * mapa por path, aplica `filter`, ordena por hits desc e pagina em
     * memória — a agregação não dá pra fazer em SQL porque os dados
     * ficam dentro de um blob JSON por linha (mesmo motivo de
     * `flagScraperPath` iterar em PHP em vez de query direta).
     */
    protected function buildPagesResult(int $page, int $perPage, string $filter, ?string $dateFrom, ?string $dateTo): array
    {
        $query = Monitor::query();

        if ($dateFrom) {
            $query->where('updated_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('updated_at', '<=', $dateTo);
        }

        $aggregated = [];

        $query->select('data')->chunk(200, function ($monitors) use (&$aggregated) {
            foreach ($monitors as $monitor) {
                $notFound = (array) data_get($monitor, 'data.not_found', []);

                foreach ((array) data_get($monitor, 'data.page', []) as $path => $hits) {
                    $aggregated[$path] ??= [
                        'path' => $path,
                        'hits' => 0,
                        'not_found' => false,
                    ];

                    $aggregated[$path]['hits'] += (int) $hits;

                    if (! empty($notFound[$path])) {
                        $aggregated[$path]['not_found'] = true;
                    }
                }
            }
        });

        $blockedPaths = BlockedPath::pluck('path');
        // Só os paths marcados 'safe' importam pro match por sufixo (mesmo
        // padrão de $blockedPaths acima) — qualquer path sem linha em
        // monitor_path_reviews é 'pending' por padrão, sem precisar de
        // linha nenhuma pra representar esse estado.
        $safePaths = PathReview::where('status', 'safe')->pluck('path');

        foreach ($aggregated as $path => &$row) {
            $row['blocked'] = $blockedPaths->contains(
                fn ($blockedPath) => $path === $blockedPath || str_ends_with($path, '/' . $blockedPath)
            );
            $row['status'] = $safePaths->contains(
                fn ($safePath) => $path === $safePath || str_ends_with($path, '/' . $safePath)
            ) ? 'safe' : 'pending';
        }
        unset($row);

        $filtered = array_values(array_filter($aggregated, function ($row) use ($filter) {
            return match ($filter) {
                '404' => $row['not_found'],
                'clean' => ! $row['not_found'] && ! $row['blocked'],
                'blocked' => $row['blocked'],
                'pending_review' => $row['not_found'] && $row['status'] !== 'safe' && ! $row['blocked'],
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
     * um humano revisar o IP. `safe` (setado via markIpSafe, nunca tocado
     * por recordVisit) é quem manda na fila de revisão: `filter=flagged`
     * exclui IPs já marcados `safe`, e a ordenação sempre prioriza
     * `flagged = true AND safe = false` (a fila de trabalho de verdade)
     * antes de cair no desempate por `visit_count`, não importa o filtro
     * pedido.
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
            // safe = false: um IP marcado safe já foi revisado por um
            // humano — não deve reaparecer na fila de "flagged" só porque
            // a heurística automática disparou de novo num request
            // seguinte (ver IpStat::recordVisit).
            'flagged' => $query->where('flagged', true)->where('safe', false),
            'clean' => $query->where('flagged', false)->whereNotIn('ip', $blockedIps),
            'blocked' => $query->whereIn('ip', $blockedIps),
            default => null,
        };

        $paginator = $query
            // flagged=true AND safe=false primeiro (fila de revisão real),
            // resto depois — dentro de cada grupo, desempata por
            // visit_count desc, igual antes da task 80. CASE WHEN em vez
            // de orderByRaw por coluna booleana composta: nem MySQL nem
            // SQLite deixam ordenar direto por uma expressão booleana
            // combinada sem isso.
            ->orderByRaw('CASE WHEN flagged = 1 AND safe = 0 THEN 0 ELSE 1 END')
            ->orderByDesc('visit_count')
            ->paginate($perPage, ['ip', 'visit_count', 'first_seen', 'last_seen', 'flagged', 'flagged_signals', 'safe'], 'page', $page);

        $items = collect($paginator->items())->map(function (IpStat $stat) use ($blockedIps) {
            return [
                'ip' => $stat->ip,
                'visit_count' => $stat->visit_count,
                'first_seen' => optional($stat->first_seen)->toIso8601String(),
                'last_seen' => optional($stat->last_seen)->toIso8601String(),
                'flagged' => $stat->flagged,
                'flagged_signals' => $stat->flagged_signals,
                'safe' => $stat->safe,
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
     * diferentes), aqui o match é direto (IP não tem variação de host) -
     * é o mesmo escaneamento em chunks, só invertido: filtra por IP em vez
     * de path, e agrega paths em vez de IPs. Sem paginação/cache: dataset
     * pequeno por IP, chamada sob demanda ao expandir uma linha no
     * dashboard, não em toda carga de página.
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

        $aggregated = [];

        Monitor::query()->select('data')->chunk(200, function ($monitors) use ($ip, &$aggregated) {
            foreach ($monitors as $monitor) {
                $ips = (array) data_get($monitor, 'data.ips', []);

                if (! in_array($ip, $ips, true)) {
                    continue;
                }

                foreach ((array) data_get($monitor, 'data.page', []) as $path => $hits) {
                    $aggregated[$path] = ($aggregated[$path] ?? 0) + (int) $hits;
                }
            }
        });

        arsort($aggregated);

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'paths' => collect($aggregated)
                ->map(fn ($hits, $path) => ['path' => $path, 'hits' => $hits])
                ->values(),
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
     * Listagem paginada de `monitor_blocked_paths` — mesmo padrão de
     * getBlockedIps.
     */
    protected function getBlockedPaths(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $cacheKey = $this->listingsCacheKey('blocked-paths', [$page, $perPage]);
        $ttl = now()->addMinutes((int) config('monitor.listings_cache_ttl_minutes', 5));

        $result = Cache::remember($cacheKey, $ttl, function () use ($page, $perPage) {
            $paginator = BlockedPath::query()
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
     * conta linhas e agrega `MAX(updated_at)` direto em SQL, agrupando
     * pela coluna gerada indexada (`monitors_user_id`, task 40) em MySQL
     * — nunca `where('data->user_id', ...)` cru, que não usa o índice
     * (ver `Monitor::scopeForUserId()`).
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

        $paginator = Monitor::query()
            ->whereNotNull($column)
            ->select("{$column} as user_id")
            ->selectRaw('COUNT(*) as visits_count')
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
                    ? \Illuminate\Support\Carbon::parse($row->last_activity)->toIso8601String()
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
        $version = Cache::get('monitor:listings:version', 1);

        return "monitor:listings:{$prefix}:v{$version}:" . md5(json_encode($params));
    }

    /**
     * Incrementa o contador de versão lido por getVisitorsByIp/
     * getBlockedIps/getBlockedPaths — chamado por updateBlockedIps/
     * unblockIp/flagScraperPath/unflagPath, já que todas mudam o estado
     * de bloqueio refletido nessas listagens.
     */
    protected function invalidateListingsCache(): void
    {
        if (! Cache::has('monitor:listings:version')) {
            Cache::forever('monitor:listings:version', 1);
        }

        Cache::increment('monitor:listings:version');
    }

    /**
     * Regenera o arquivo de deny-list (ver `monitor:export-denylist`) sempre
     * que `monitor_blocked_ips` muda, se `monitor.denylist_auto_export`
     * estiver ligado (opt-in, default false). Chamado só pelos 3 pontos que
     * de fato mexem em `monitor_blocked_ips` (updateBlockedIps/unblockIp/
     * flagScraperPath) - unflagPath não mexe nessa tabela, não precisa
     * regenerar nada. Fail-open: escrita em disco não pode derrubar a
     * action principal de bloquear/desbloquear IP.
     */
    protected function maybeAutoExportDenylist(): void
    {
        if (! config('monitor.denylist_auto_export')) {
            return;
        }

        try {
            DenylistExporter::writeToDisk(config('monitor.denylist_format', 'apache'));
        } catch (\Throwable $e) {
            Log::error('Monitor denylist auto-export failed: ' . $e->getMessage());
        }
    }

    protected function clearData(Request $request)
    {
        // futuramente: validação/admin check
        Monitor::truncate();

        return response()->json([
            'success' => true,
            'message' => 'All monitor data cleared'
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

        $cutoff = now()->subDays((int) $olderThanDays);

        $monitorsDeleted = $this->pruneMonitors($cutoff, $onlyBlocked);

        $ipStatQuery = IpStat::where('last_seen', '<', $cutoff);
        if ($onlyBlocked) {
            $ipStatQuery->whereIn('ip', BlockedIp::pluck('ip'));
        }
        $ipStatsDeleted = $ipStatQuery->delete();

        if ($monitorsDeleted > 0) {
            $this->invalidatePagesCache();
        }

        if ($ipStatsDeleted > 0) {
            $this->invalidateListingsCache();
        }

        return response()->json([
            'success' => true,
            'monitors_deleted' => $monitorsDeleted,
            'ip_stats_deleted' => $ipStatsDeleted,
        ]);
    }

    /**
     * Sem `only_blocked`, o delete é direto em SQL (bulk, sem carregar
     * nada em PHP). Com `only_blocked=true`, precisa do mesmo contorno de
     * `buildPagesResult`: `data.ips` mora dentro do blob JSON de
     * `Monitor.data`, sem coluna própria pra filtrar de forma portável
     * entre sqlite/mysql/pgsql — então junta os ids em PHP via chunk e só
     * deleta ao final (nunca durante o chunk). Match por IP confirmado-
     * bloqueado (mesmo estilo de `flagScraperPath`/`buildVisitorsResult`,
     * que já resolvem `monitor_blocked_ips` numa query só e testam contra
     * ela em vez de um JOIN de verdade).
     */
    protected function pruneMonitors($cutoff, bool $onlyBlocked): int
    {
        if (! $onlyBlocked) {
            return Monitor::where('updated_at', '<', $cutoff)->delete();
        }

        $blockedIps = BlockedIp::pluck('ip')->all();

        if (empty($blockedIps)) {
            return 0;
        }

        $ids = [];

        Monitor::where('updated_at', '<', $cutoff)
            ->select('id', 'data')
            ->chunkById(200, function ($monitors) use ($blockedIps, &$ids) {
                foreach ($monitors as $monitor) {
                    $ips = (array) data_get($monitor, 'data.ips', []);

                    if (array_intersect($ips, $blockedIps)) {
                        $ids[] = $monitor->id;
                    }
                }
            });

        if (empty($ids)) {
            return 0;
        }

        return Monitor::whereIn('id', $ids)->delete();
    }

    /**
     * Bloqueia uma lista de IPs (persistidos em `monitor_blocked_ips`,
     * checados em `MonitorMethod` antes do resto do tracking). Fonte fixa
     * em 'manual' por enquanto — schema já preparado pra outras origens
     * (blacklist coletiva, feed externo de reputação) no futuro.
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

        foreach ($ips as $ip) {
            if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            BlockedIp::firstOrCreate(['ip' => $ip], ['source' => 'manual']);
            Cache::forget("monitor:blocked-ip:{$ip}");
            $blocked[] = $ip;
        }

        if (empty($blocked)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IPs provided',
            ], 422);
        }

        $this->invalidateListingsCache();
        $this->maybeAutoExportDenylist();

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
        $this->maybeAutoExportDenylist();

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'was_blocked' => $removed,
        ]);
    }

    /**
     * Marca um IP como `safe` em `monitor_ip_stats`, tirando-o da fila de
     * revisão de `getVisitorsByIp` (`filter=flagged`/ordenação por
     * prioridade) mesmo que `IpStat::recordVisit` volte a marcar
     * `flagged = true` num request seguinte daquele IP — `flagged`/
     * `flagged_signals` continuam refletindo só o último request (não
     * cumulativo, ver IpStat::recordVisit), mas `safe` nunca é tocado por
     * lá, só por esta action/`unmarkIpSafe`. Não tem nenhum efeito de
     * bloqueio (diferente de updateBlockedIps/flagScraperPath); é só o
     * status de revisão exposto por getVisitorsByIp/buildVisitorsResult -
     * mesma relação que markPathSafe tem com flagScraperPath.
     *
     * `updateOrCreate` em vez do padrão manual de recordVisit
     * (first()+save()/create()): um IP pode ser marcado safe antes de
     * qualquer visita registrada (ex: IP de um parceiro conhecido,
     * cadastrado preventivamente) — quando a linha ainda não existe, os
     * demais campos (`visit_count`, `flagged`, `first_seen`, `last_seen`)
     * ficam com o default da migration; quando já existe, só `safe` é
     * sobrescrito, preservando o histórico de visitas dessa linha.
     */
    protected function markIpSafe(Request $request)
    {
        $ip = (string) $request->input('ip', '');

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IP provided',
            ], 422);
        }

        IpStat::updateOrCreate(['ip' => $ip], ['safe' => true]);

        $this->invalidateListingsCache();

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'safe' => true,
        ]);
    }

    /**
     * Reverte markIpSafe: volta `safe` pra `false` na linha de
     * `monitor_ip_stats` correspondente (não apaga a linha — ao contrário
     * de unmarkPathSafe/monitor_path_reviews, aqui `safe` é só mais uma
     * coluna de uma linha que já carrega histórico de visitas real, não
     * um registro dedicado só pra existir/não-existir). Sem efeito se o
     * IP não tiver linha em `monitor_ip_stats` ainda.
     */
    protected function unmarkIpSafe(Request $request)
    {
        $ip = (string) $request->input('ip', '');

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid IP provided',
            ], 422);
        }

        $wasSafe = IpStat::where('ip', $ip)->where('safe', true)->exists();

        IpStat::where('ip', $ip)->update(['safe' => false]);

        $this->invalidateListingsCache();

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'was_safe' => $wasSafe,
        ]);
    }

    /**
     * Flaga um path (sem host - a parte "variável" da URL, ex:
     * "wp-admin/install.php") como scrapper. Duas coisas acontecem: (1) o
     * path entra em `monitor_blocked_paths`, checado por `MonitorMethod`
     * pra bloquear (403) qualquer request futura àquele path, em qualquer
     * host que esta installation atenda; (2) os IPs que já visitaram esse
     * path (via `data.page` dos registros de Monitor) são bloqueados em
     * `monitor_blocked_ips`, mesmo mecanismo de `updateBlockedIps`.
     */
    protected function flagScraperPath(Request $request)
    {
        $path = ltrim((string) $request->input('path', ''), '/');

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        BlockedPath::firstOrCreate(['path' => $path]);
        Cache::forget("monitor:blocked-path:{$path}");
        $this->invalidatePagesCache();
        $this->invalidateListingsCache();

        $blockedIps = [];

        // `data.page` guarda chaves "host/path" - o mesmo path pode
        // aparecer sob hosts diferentes (multi-subdomínio na mesma
        // installation), por isso o match é feito pelo sufixo "/{$path}",
        // não por igualdade exata da chave.
        Monitor::all()->each(function (Monitor $monitor) use ($path, &$blockedIps) {
            $pages = (array) data_get($monitor, 'data.page', []);

            $matches = collect(array_keys($pages))->contains(
                fn ($key) => $key === $path || str_ends_with($key, '/' . $path)
            );

            if (! $matches) {
                return;
            }

            foreach ((array) data_get($monitor, 'data.ips', []) as $ip) {
                if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }

                BlockedIp::firstOrCreate(['ip' => $ip], ['source' => 'scraper-path']);
                Cache::forget("monitor:blocked-ip:{$ip}");
                $blockedIps[$ip] = true;
            }
        });

        $this->maybeAutoExportDenylist();

        return response()->json([
            'success' => true,
            'path' => $path,
            'blocked_ips' => array_keys($blockedIps),
        ]);
    }

    /**
     * Reverte `flagScraperPath` para um path: remove de
     * `monitor_blocked_paths` e limpa o cache lido por
     * `MonitorMethod::isPathBlocked()`. Não desbloqueia os IPs que
     * `flagScraperPath` bloqueou por causa desse path — isso é feito
     * separadamente via `unblockIp`.
     */
    protected function unflagPath(Request $request)
    {
        $path = ltrim((string) $request->input('path', ''), '/');

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        $removed = BlockedPath::where('path', $path)->delete() > 0;
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
     * status exposto por getPages/buildPagesResult.
     */
    protected function markPathSafe(Request $request)
    {
        $path = ltrim((string) $request->input('path', ''), '/');

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        PathReview::updateOrCreate(
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
     * Reverte markPathSafe: apaga a linha de monitor_path_reviews, e o
     * path volta a ser 'pending' por padrão (mesma linha de raciocínio
     * de unblockIp/unflagPath removendo em vez de gravar um segundo
     * estado explícito).
     */
    protected function unmarkPathSafe(Request $request)
    {
        $path = ltrim((string) $request->input('path', ''), '/');

        if ($path === '') {
            return response()->json([
                'success' => false,
                'message' => 'No path provided',
            ], 422);
        }

        $removed = PathReview::where('path', $path)->where('status', 'safe')->delete() > 0;
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
            'message' => 'Monitoring rules updated (stub)'
        ]);
    }
}
