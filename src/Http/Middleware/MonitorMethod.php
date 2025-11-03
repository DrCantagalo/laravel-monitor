<?php

namespace Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Session;
use Monitor\Models\Monitor;

class MonitorMethod
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        if(session('remember_me')) {
            $token = session('remember_me');
            $user = Monitor::where('data->id-token', $token)->first();
            if ($user) {
                if(session('monitor_id', false) && session('monitor_id') != $user->id){ 
                    Monitor::find(session('monitor_id'))?->delete();
                }
                $user->newVisit(session()->getId());
                Session::put('monitor_id', $user->id);
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
                'sessions' => [session()->getId()]
            ];
            $user = Monitor::create(['data' => $data]);
            Session::put('monitor_id', $user->id);
        }
        return $next($request);
    }
}
