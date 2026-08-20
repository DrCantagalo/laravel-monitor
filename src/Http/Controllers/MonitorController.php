<?php

namespace Drcantagalo\LaravelMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\BlockedPath;

class MonitorController extends Controller
{
    /**
     * Ação pública chamada pelo front-end do site monitorado (não passa
     * pelo gate de bearer token de handle() — é o visitante, não o painel
     * admin). Lê o cookie de remember-me e sinaliza pro MonitorMethod
     * middleware reconhecer esse visitante nesta mesma requisição.
     */
    public function rememberMe(Request $request)
    {
        $token = $request->cookie(config('monitor.remember_cookie', 'monitor_id_token'));

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'No remember-me cookie present',
            ], 400);
        }

        session(['remember_me' => $token]);

        return response()->json(['success' => true]);
    }

    /**
     * Chaves internas do Monitor que a action pública updateData nunca pode
     * sobrescrever, mesmo que o front-end envie um par com esse nome.
     */
    protected const PROTECTED_DATA_KEYS = [
        'sessions', 'ips', 'visits', 'page', 'id-token', 'ua', 'user_id',
    ];

    /**
     * Ação pública chamada pelo front-end do site monitorado (mesmo padrão
     * de rememberMe: visitante, não painel admin, sem gate de bearer
     * token). Grava pares chave/valor arbitrários em `data` do Monitor da
     * sessão atual — base de segmentação/tags (idioma, preferências etc.),
     * não é CRM/lead ainda. Chaves internas usadas pelo MonitorMethod
     * (PROTECTED_DATA_KEYS) são ignoradas silenciosamente para não
     * corromper o tracking.
     */
    public function updateData(Request $request)
    {
        $monitorId = session('monitor_id');

        if (! $monitorId) {
            return response()->json([
                'success' => false,
                'message' => 'No active monitor session',
            ], 400);
        }

        $user = Monitor::find($monitorId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No active monitor session',
            ], 400);
        }

        $payload = $request->input('data', []);

        if (! is_array($payload) || empty($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'No data provided',
            ], 422);
        }

        $data = $user->data;

        foreach ($payload as $key => $value) {
            if (in_array($key, self::PROTECTED_DATA_KEYS, true)) {
                continue;
            }

            $data[$key] = $value;
        }

        $user->data = $data;
        $user->save();

        return response()->json([
            'success' => true,
            'monitor_id' => $user->id,
        ]);
    }

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

        // Token de leitura efêmero (issueReadToken) só é aceito pra
        // action getData — nunca pra clearData/updateBlockedIps/
        // updateRules/issueReadToken, que exigem o local_token permanente.
        $isValidReadToken = $action === 'getData'
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

            case 'clearData':
                return $this->clearData($request);

            case 'updateBlockedIps':
                return $this->updateBlockedIps($request);

            case 'flagScraperPath':
                return $this->flagScraperPath($request);

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
            'data' => $data
        ]);
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

        return response()->json([
            'success' => true,
            'blocked' => $blocked,
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

        return response()->json([
            'success' => true,
            'path' => $path,
            'blocked_ips' => array_keys($blockedIps),
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
