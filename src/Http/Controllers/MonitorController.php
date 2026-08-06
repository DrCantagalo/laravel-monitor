<?php

namespace Drcantagalo\LaravelMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Drcantagalo\LaravelMonitor\Models\Monitor;

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
     * Handler principal para ações do monitor
     */
    public function handle(Request $request)
    {
        $token = $request->bearerToken();
        $expected = config('monitor.local_token');

        if (! $expected || ! $token || ! hash_equals($expected, $token)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $action = $request->query('action', 'getData');

        switch ($action) {
            case 'getData':
                return $this->getData($request);

            case 'clearData':
                return $this->clearData($request);

            case 'updateBlockedIps':
                return $this->updateBlockedIps($request);

            case 'updateRules':
                return $this->updateRules($request);

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid action'
                ], 400);
        }
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

    protected function updateBlockedIps(Request $request)
    {
        // implementar depois
        return response()->json([
            'success' => true,
            'message' => 'Blocked IPs updated (stub)'
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
