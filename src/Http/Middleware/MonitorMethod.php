<?php

namespace Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Monitor\Models\Monitor;
use Exception;

class MonitorMethod
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningInConsole() || !$request->hasSession()) {
            return $next($request);
        }

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

        return $next($request);
    }
}