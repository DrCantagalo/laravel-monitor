<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SessionVisitorTracker
{
    /**
     * Rastreia o visitante com sessão (web): remember-me, criação/
     * atualização do registro Monitor e cookie de remember quando um
     * Monitor novo é criado.
     */
    public function track(Request $request, Response $response, string $path, ?string $userAgent, string $ip): void
    {
        if (session('avoid_monitor', false)) {
            session()->forget('avoid_monitor');

            return;
        }

        $user = null;

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

        if (session('monitor_id')) {
            if (!$user) {
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

            return;
        }

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
