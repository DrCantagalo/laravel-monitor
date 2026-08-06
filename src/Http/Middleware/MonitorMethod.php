<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Exception;

class MonitorMethod
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. LÓGICA DE "IDA"
        if (app()->runningInConsole()) {
            return $next($request);
        }

        // Capturamos dados básicos antes do processamento
        $path = $request->path();
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        // 2. PROCESSAMENTO (O Laravel segue para os outros middlewares e para o Controller)
        $response = $next($request);

        // 3. LÓGICA DE "VOLTA" (Agora a Session já está disponível!)
        try {
            if ($request->hasSession()) {
                // Lógica para usuários com Sessão (Web)
                if (!session('avoid_monitor', false)) {
                    
                    // Tratamento do Remember Me
                    if (session('remember_me')) {
                        $token = session('remember_me');
                        $user = Monitor::where('data->id-token', $token)->first();
                        
                        if ($user) {
                            if (session('monitor_id') && session('monitor_id') != $user->id) {
                                Monitor::where('id', session('monitor_id'))->delete();
                            }
                            
                            $user->newVisit(session()->getId(), $ip);
                            session(['monitor_id' => $user->id]);
                        }
                        session()->forget('remember_me');
                    }

                    // Atualização ou Criação
                    if (session('monitor_id')) {
                        if (!isset($user)) {
                            $user = Monitor::find(session('monitor_id'));
                        }
                        if ($user) {
                            $data = $user->data;
                            $data['page'] = $data['page'] ?? [];
                            $data['page'][$path] = ($data['page'][$path] ?? 0) + 1;
                            $data['ua'] = $userAgent;
                            
                            $user->data = $data;
                            $user->save();
                        }
                    } else {
                        $rememberToken = Str::random(40);

                        $data = [
                            'page' => [$path => 1],
                            'sessions' => [session()->getId()],
                            'ips'  => [$ip],
                            'ua'   => $userAgent,
                            'id-token' => $rememberToken,
                        ];

                        $user = Monitor::create(['data' => $data]);
                        session(['monitor_id' => $user->id]);

                        $response->headers->setCookie(cookie(
                            config('monitor.remember_cookie', 'monitor_id_token'),
                            $rememberToken,
                            config('monitor.remember_cookie_days', 1825) * 1440
                        ));
                    }
                }
                else {
                    session()->forget('avoid_monitor');
                }
            } else {
                // Lógica para usuários SEM Sessão (API / Bots / Scrapers)
                $user = Monitor::where('data->ips', 'like', "%{$ip}%")->first();

                if ($user) {
                    $data = $user->data;
                    $data['page'][$path] = ($data['page'][$path] ?? 0) + 1;
                    
                    $ips = $data['ips'] ?? [];
                    if (!in_array($ip, $ips)) {
                        $ips[] = $ip;
                        $data['ips'] = $ips;
                    }
                    
                    $user->data = $data;
                    $user->save();
                } else {
                    $data = [
                        'page'     => [$path => 1],
                        'sessions' => [],
                        'ips'      => [$ip],
                        'ua'       => $userAgent,
                    ];

                    Monitor::create(['data' => $data]);
                }
            }
        } catch (Exception $e) {
            Log::error("Monitor Package Error: " . $e->getMessage());
        }

        return $response;
    }
}