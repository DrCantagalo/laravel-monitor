<?php

namespace Monitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Monitor\Models\Monitor;

class MonitorController extends Controller
{
    /**
     * Handler principal para ações do monitor
     */
    public function handle(Request $request)
    {
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
