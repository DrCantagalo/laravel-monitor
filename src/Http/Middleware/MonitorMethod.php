<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Exception;

class MonitorMethod
{
    public function handle(Request $request, Closure $next): Response
    {
        
        if (app()->runningInConsole()) {
            return $next($request);
        }

        if ($request->hasSession()) {
            try {
                if (!session('avoid_monitor', false)) {
                    $path = $request->path();

                    if (session('remember_me')) {
                        $token = session('remember_me');
                        $user = Monitor::where('data->id-token', $token)->first();

                        if ($user) {
                            if (session('monitor_id') && session('monitor_id') != $user->id) {
                                Monitor::where('id', session('monitor_id'))->delete();
                            }
                            
                            $user->newVisit(session()->getId(), $request->ip());
                            session(['monitor_id' => $user->id]);
                        }
                        session()->forget('remember_me');
                    }

                    if (session('monitor_id')) {
                        $user = Monitor::find(session('monitor_id'));
                        if ($user) {
                            $data = $user->data;
                            $data['page'] = $data['page'] ?? [];
                            $data['page'][$path] = ($data['page'][$path] ?? 0) + 1;
                            
                            $user->data = $data;
                            $user->save();
                        }
                    } else {
                        $data = [
                            'page' => [$path => 1],
                            'sessions' => [session()->getId()],
                            'ips'  => [$request->ip()]
                        ];
                        
                        $user = Monitor::create(['data' => $data]);
                        session(['monitor_id' => $user->id]);
                    }
                } else {
                    session()->forget('avoid_monitor');
                }
            } catch (Exception $e) {
                Log::error("Monitor Package Error: " . $e->getMessage());
            }
        }
        else {
            try {
                $path = $request->path();
                $ip = $request->ip();
                $userAgent = $request->header('User-Agent');
                $sessionId = $request->hasSession() ? session()->getId() : 'no-session';

                // 1. Tentar identificar um registro existente pelo IP (ou sessão se preferir)
                // Para segurança/brute-force, o IP é o rastro mais sólido.
                $user = Monitor::where('data->ips', 'like', "%{$ip}%")->first();

                if ($user) {
                    $data = $user->data;

                    // Atualiza contagem de páginas
                    $data['page'][$path] = ($data['page'][$path] ?? 0) + 1;

                    // Registra nova sessão se for diferente
                    if (!isset($data['sessions']) || !in_array($sessionId, $data['sessions'])) {
                        $data['sessions'][] = $sessionId;
                    }

                    // Registra novo IP se for diferente (caso o usuário mude de rede)
                    if (!in_array($ip, $data['ips'])) {
                        $data['ips'][] = $ip;
                    }

                    $user->data = $data;
                    $user->save();

                    // Se houver sessão, vinculamos o ID do banco nela para facilitar buscas futuras
                    if ($request->hasSession()) {
                        session(['monitor_id' => $user->id]);
                    }
                } else {
                    // 2. Cria um novo registro para este novo "visitante" (IP novo)
                    $data = [
                        'page'     => [$path => 1],
                        'sessions' => [$sessionId],
                        'ips'      => [$ip],
                        'ua'       => $userAgent, // Guardar User Agent ajuda a detectar bots/scrapers
                        'created_at' => now()->toDateTimeString()
                    ];

                    $user = Monitor::create(['data' => $data]);
                    
                    if ($request->hasSession()) {
                        session(['monitor_id' => $user->id]);
                    }
                }

            } catch (Exception $e) {
                // Logamos o erro mas não travamos a aplicação do usuário
                Log::error("Monitor Package Error: " . $e->getMessage());
            }
        }

        return $next($request);
    }
}