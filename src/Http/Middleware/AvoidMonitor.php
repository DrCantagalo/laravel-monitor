<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Drcantagalo\LaravelMonitor\Facades\Monitor;
use Illuminate\Http\Request;

/**
 * Exclusão PERMANENTE de tracking por rota/grupo (laravel-monitor 151) —
 * complementar, não substitui, `Monitor::skipTracking()`. `skipTracking()`
 * é circunstancial: precisa ser chamado explicitamente dentro de cada
 * controller/action, fácil de esquecer numa rota nova. Uma rota que NUNCA
 * deve ser rastreada (polling automático, endpoints internos dedicados)
 * declara isso na própria definição da rota em vez de depender de alguém
 * lembrar de chamar a facade lá dentro. Ver README "Advanced usage" pra
 * quando usar cada um.
 *
 * Reusa o mesmo mecanismo de `Monitor::skipTracking()` (flag de sessão,
 * `config('monitor.skip_session_key')`) em vez de duplicar lógica — chama
 * ANTES de `$next($request)`, garantindo que a flag já esteja setada
 * quando a "lógica de volta" do `MonitorMethod` rodar (só executa depois
 * que todo o pipeline interno retorna, ver "3. LÓGICA DE VOLTA" em
 * `MonitorMethod::handle()`). Isso também torna a combinação com uma
 * chamada manual de `skipTracking()` dentro da mesma rota idempotente: a
 * segunda chamada só regrava a mesma flag `true` já setada pela primeira.
 */
class AvoidMonitor
{
    public function handle(Request $request, Closure $next)
    {
        Monitor::skipTracking();

        return $next($request);
    }
}
