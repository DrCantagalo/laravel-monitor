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

    protected ScraperBlocker $scraperBlocker;

    protected BlockedIpCleaner $blockedIpCleaner;

    public function __construct(?ScraperSignalDetector $scraperSignalDetector = null, ?ScraperBlocker $scraperBlocker = null, ?BlockedIpCleaner $blockedIpCleaner = null)
    {
        $this->scraperSignalDetector = $scraperSignalDetector ?? new ScraperSignalDetector;
        $this->scraperBlocker = $scraperBlocker ?? new ScraperBlocker;
        $this->blockedIpCleaner = $blockedIpCleaner ?? new BlockedIpCleaner;
    }

    /**
     * laravel-monitor 96: promoção automática de flagged->blocked quando os
     * sinais de scraper da request atual passam de `auto_block_signal_threshold`
     * (separado e mais alto que `scraper_signal_threshold`, que só decide
     * `data.flags.scraper` pra revisão humana - autoblock age sozinho e
     * merece mais confiança). Entra na escada temporária/escalonada de
     * `ScraperBlocker`, não vira permanente direto.
     */
    protected function maybeAutoBlock(string $ip, array $signals): void
    {
        $threshold = (int) config('monitor.auto_block_signal_threshold', 3);

        if (count($signals) >= $threshold) {
            $this->scraperBlocker->registerOffense($ip, 'auto-signal');
        }
    }

    /**
     * Grava o IP em `monitor_visit_ips` só quando difere do último IP
     * gravado nesta sessão (`monitor_last_ip`) — evita um INSERT por
     * request e, de quebra, captura a troca de IP no meio da sessão
     * (rede móvel), que antes só o tracker anônimo registrava.
     */
    protected function recordIpIfChanged(Monitor $monitor, string $ip): void
    {
        if (session('monitor_last_ip') === $ip) {
            return;
        }

        $monitor->recordIp($ip);
        session(['monitor_last_ip' => $ip]);
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
            $user = Monitor::where('id_token', $token)->first();

            if ($user) {
                if (session('monitor_id') && session('monitor_id') != $user->id) {
                    // O cascade da FK leva as visitas do monitor efêmero
                    // junto; o id da visita na sessão fica órfão e
                    // VisitRecorder (que valida id + monitor_id) cria uma
                    // nova pro monitor adotado.
                    Monitor::where('id', session('monitor_id'))->delete();
                    session()->forget(VisitRecorder::SESSION_KEY);
                }

                $this->recordIpIfChanged($user, $ip);
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
                $user = Monitor::where('id_token', $cookieToken)->first();

                if ($user) {
                    $this->recordIpIfChanged($user, $ip);
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
                $data['ua'] = $userAgent;

                if (config('monitor.track_authenticated_user', true) && Auth::check()) {
                    $data['user_id'] = Auth::id();
                }

                $visitCount = IpStat::visitCount($ip) + 1;
                $signals = $this->scraperSignalDetector->detect($request, $ip, $userAgent, $visitCount);
                $isScraper = $this->scraperSignalDetector->isScraper($signals);
                $data['flags'] = $data['flags'] ?? [];
                $data['flags']['scraper'] = $isScraper;
                $data['flags']['scraper_signals'] = $signals;
                IpStat::recordVisit($ip, $isScraper, $signals);
                $this->maybeAutoBlock($ip, $signals);
                $this->blockedIpCleaner->maybeCleanup();
                DataPruner::maybeCleanup();

                $user->data = $data;

                // Hits, IPs e a jornada da visita vivem em tabelas filhas
                // (`monitor_page_hits`/`monitor_visit_ips`/`monitor_visits`),
                // gravadas direto — o blob só guarda ua/flags/user_id/tags.
                $user->recordHit($path, $notFound);
                $this->recordIpIfChanged($user, $ip);
                VisitRecorder::record($user, $path, $isScraper);

                // touch(), não save(): `updated_at` é a "última atividade"
                // (filtro de datas de getPages, DataPruner, last_activity
                // de usuários) e o blob nem sempre muda mais a cada
                // request — sem isso o Eloquent pularia o UPDATE. touch()
                // ainda grava os atributos sujos (ua/flags) quando houver.
                $user->touch();
            }

            return;
        }

        $rememberToken = Str::random(40);
        $visitCount = IpStat::visitCount($ip) + 1;
        $signals = $this->scraperSignalDetector->detect($request, $ip, $userAgent, $visitCount);
        $isScraper = $this->scraperSignalDetector->isScraper($signals);
        IpStat::recordVisit($ip, $isScraper, $signals);
        $this->maybeAutoBlock($ip, $signals);
        $this->blockedIpCleaner->maybeCleanup();
        DataPruner::maybeCleanup();

        $data = [
            'ua' => $userAgent,
            'flags' => [
                'scraper' => $isScraper,
                'scraper_signals' => $signals,
            ],
        ];

        if (config('monitor.track_authenticated_user', true) && Auth::check()) {
            $data['user_id'] = Auth::id();
        }

        $user = Monitor::create(['data' => $data, 'id_token' => $rememberToken]);
        session(['monitor_id' => $user->id]);

        $user->recordHit($path, $notFound);
        $this->recordIpIfChanged($user, $ip);
        VisitRecorder::record($user, $path, $isScraper);

        $response->headers->setCookie(cookie(
            config('monitor.remember_cookie', 'monitor_id_token'),
            $rememberToken,
            config('monitor.remember_cookie_days', 1825) * 1440
        ));
    }
}
