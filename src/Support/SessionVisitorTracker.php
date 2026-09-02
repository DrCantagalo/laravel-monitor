<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SessionVisitorTracker
{
    protected ScraperSignalDetector $scraperSignalDetector;

    public function __construct(?ScraperSignalDetector $scraperSignalDetector = null)
    {
        $this->scraperSignalDetector = $scraperSignalDetector ?? new ScraperSignalDetector;
    }

    /**
     * Rastreia o visitante com sessão (web): remember-me, criação/
     * atualização do registro Monitor e cookie de remember quando um
     * Monitor novo é criado.
     */
    public function track(Request $request, Response $response, string $path, ?string $userAgent, string $ip, bool $notFound = false): void
    {
        $skipKey = config('monitor.skip_session_key', 'avoid_monitor');

        if (session($skipKey, false)) {
            session()->forget($skipKey);

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

        // Antes de criar uma linha nova, tenta reconectar direto pelo
        // cookie de remember-me (chega no servidor via header a cada
        // request, independente de ser httpOnly - isso só impede leitura
        // via JS/document.cookie, nunca impediu o backend de ler). Sem
        // isso, a PRIMEIRA request de toda sessão nova (session('monitor_id')
        // ainda não setado, e o app hospedeiro ainda não teve chance de
        // chamar o endpoint dedicado GET /monitor/remember-me) sempre
        // caía direto no ramo de criar Monitor novo abaixo, sobrescrevendo
        // o cookie do visitante antigo antes de qualquer front-end
        // conseguir usá-lo - o remember-me nunca reconectava de fato um
        // visitante que voltava com a sessão PHP expirada.
        if (! $user && ! session('monitor_id')) {
            $cookieToken = $request->cookie(config('monitor.remember_cookie', 'monitor_id_token'));

            if ($cookieToken) {
                $user = Monitor::where('data->id-token', $cookieToken)->first();

                if ($user) {
                    $user->newVisit(session()->getId(), $ip);
                    session(['monitor_id' => $user->id]);
                }
            }
        }

        if (session('monitor_id')) {
            if (! $user) {
                $user = Monitor::find(session('monitor_id'));
            }
            if ($user) {
                $data = $user->data;
                $data['page'] = $data['page'] ?? [];
                $data['page'][$path] = ($data['page'][$path] ?? 0) + 1;
                $data['ua'] = $userAgent;
                // Todo request rastreado conta como uma visita, igual aos
                // dois caminhos de reconexão via remember-me acima
                // (Monitor::newVisit) — sem isso, `data.visits` nunca
                // avançava no caminho mais comum (sessão já rastreada),
                // deixando o contador de "Visits" por usuário do dashboard
                // sempre travado em 1 (task 92).
                $data['visits'] = ($data['visits'] ?? 0) + 1;

                if ($notFound) {
                    $data['not_found'] = $data['not_found'] ?? [];
                    $data['not_found'][$path] = true;
                }

                if (config('monitor.track_authenticated_user', true) && Auth::check()) {
                    $data['user_id'] = Auth::id();
                }

                $signals = $this->scraperSignalDetector->detect($request, $ip, $userAgent);
                $isScraper = $this->scraperSignalDetector->isScraper($signals);
                $data['flags'] = $data['flags'] ?? [];
                $data['flags']['scraper'] = $isScraper;
                $data['flags']['scraper_signals'] = $signals;
                IpStat::recordVisit($ip, $isScraper, $signals);

                $user->data = $data;
                $user->save();
            }

            return;
        }

        $rememberToken = Str::random(40);
        $signals = $this->scraperSignalDetector->detect($request, $ip, $userAgent);
        $isScraper = $this->scraperSignalDetector->isScraper($signals);
        IpStat::recordVisit($ip, $isScraper, $signals);

        $data = [
            'page' => [$path => 1],
            'sessions' => [session()->getId()],
            'ips' => [$ip],
            'ua' => $userAgent,
            'id-token' => $rememberToken,
            'flags' => [
                'scraper' => $isScraper,
                'scraper_signals' => $signals,
            ],
        ];

        if ($notFound) {
            $data['not_found'] = [$path => true];
        }

        if (config('monitor.track_authenticated_user', true) && Auth::check()) {
            $data['user_id'] = Auth::id();
        }

        $user = Monitor::create(['data' => $data]);
        session(['monitor_id' => $user->id]);

        $response->headers->setCookie(cookie(
            config('monitor.remember_cookie', 'monitor_id_token'),
            $rememberToken,
            config('monitor.remember_cookie_days', 1825) * 1440
        ));
    }
}
