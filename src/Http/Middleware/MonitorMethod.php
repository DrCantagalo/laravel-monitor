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
