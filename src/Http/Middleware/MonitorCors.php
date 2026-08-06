<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * CORS dedicado às rotas monitor/* do pacote — não depende/mexe no
 * config/cors.php do app hospedeiro (cada site cliente tem um Laravel
 * diferente, editar a config deles não é confiável). Permite que o
 * dashboard (subdomínio monitor) chame o handler direto do navegador do
 * usuário final.
 */
class MonitorCors
{
    public function handle(Request $request, Closure $next)
    {
        $origin = config('monitor.dashboard_origin', 'https://monitor.cantagalo.it');

        if ($request->getMethod() === 'OPTIONS') {
            $response = response()->noContent(204);
        } else {
            $response = $next($request);
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
