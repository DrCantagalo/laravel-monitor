<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * laravel-monitor 284 (v0.59.0): auto-classifica como `human` (
     * `source=auth`) um Monitor que acabou de logar — quem autenticou é,
     * por definição, uma pessoa (ou pelo menos tem uma conta de verdade por
     * trás), então não faz sentido esse Monitor continuar na fila de
     * triagem IA pra sempre só porque nunca foi classificado manualmente.
     *
     * Só os dois call sites de `track()` que acabaram de setar
     * `data['user_id']` pela primeira vez chamam este método (ver
     * `$newlyAuthenticated` nos dois ramos abaixo) — é esse "só na
     * transição guest->autenticado" que faz o custo ficar barato: depois
     * da primeira vez, `user_id` já está em `data` e o ramo nunca mais
     * entra aqui pra este Monitor, então nenhuma query roda nas requests
     * seguintes. Monitors que já tinham `user_id` antes desta feature
     * existir não passam por essa transição de novo — ver o backfill em
     * `MonitorUpdateCommand`/migration idempotente.
     *
     * `firstOrNew` + checagem de `kind` em PHP (não um `INSERT ... WHERE
     * NOT EXISTS` ou `upsert`): precisa checar `kind !== null` antes de
     * decidir se escreve, não só "existe linha ou não" — uma linha já
     * existente SEM `kind` (ex: só com `tags`/`note`) deve ganhar
     * `kind=human` normalmente, mas uma com `kind=bot`/`human` manual ou
     * de IA NUNCA pode ser sobrescrita (um bot manual que loga continua
     * bot).
     */
    protected function maybeAutoHumanClassify(int $monitorId): void
    {
        // laravel-monitor 295 (v0.61.0): a tag reservada `user` é
        // sincronizada ANTES do early-return de `kind` abaixo — ela não é
        // protegida pela mesma regra de "não sobrescrever uma
        // classificação manual" que `kind` é: um Monitor classificado
        // manualmente como bot que loga continua bot, mas ainda deve
        // ganhar a tag `user` (a tag só descreve "autenticado", não o
        // julgamento bot/human).
        $this->syncUserTag($monitorId, true);

        $label = MonitorLabel::firstOrNew(['monitor_id' => $monitorId]);

        if ($label->kind !== null) {
            return;
        }

        $label->kind = 'human';
        $label->source = 'auth';
        $label->classified_at = now();

        // Com o `unique` em monitor_id (migration 2026-10-04), duas
        // requests simultâneas criando o primeiro rótulo do mesmo Monitor
        // não duplicam mais a linha: a perdedora cai aqui e desiste — o
        // rótulo que venceu (qualquer origem) não deve ser sobrescrito.
        try {
            $label->save();
        } catch (UniqueConstraintViolationException) {
            return;
        }
    }

    /**
     * laravel-monitor 295 (v0.61.0): mantém a tag reservada
     * `MonitorLabel::TAG_USER` em sincronia com `data['user_id']` — chamado
     * só a partir de `maybeAutoHumanClassify()` (mesmo gate: só na
     * transição guest->autenticado, nunca a cada request). `$hasUserId`
     * sempre chega `true` nos dois call sites de hoje (não existe, no
     * pacote, nenhuma transição autenticado->guest que zere `user_id` de
     * um Monitor já existente); o parâmetro fica genérico mesmo assim
     * (`true` adiciona, `false` remove) pra não deixar a remoção
     * implementada de forma incompleta se um dia existir essa transição —
     * ver README "IP classification"/"Authenticated user tagging".
     *
     * Não usa `lockForUpdate()`/transaction (diferente de
     * `setMonitorTags()`): mesmo raciocínio de `maybeAutoHumanClassify()`
     * acima — o `unique` em `monitor_id` (migration 2026-10-04) já evita
     * duplicar a linha numa corrida, e a pior consequência de perder uma
     * corrida aqui é a MESMA tag não ser adicionada numa chamada
     * concorrente, que nunca aconteceria de novo pro mesmo Monitor (só
     * dispara na transição, uma vez por Monitor).
     */
    protected function syncUserTag(int $monitorId, bool $hasUserId): void
    {
        $label = MonitorLabel::firstOrNew(['monitor_id' => $monitorId]);
        $tags = $label->tags ?? [];
        $hasTag = in_array(MonitorLabel::TAG_USER, $tags, true);

        if ($hasUserId === $hasTag) {
            return;
        }

        $label->tags = $hasUserId
            ? MonitorLabel::normalizeTags([...$tags, MonitorLabel::TAG_USER])
            : array_values(array_filter($tags, fn ($t) => $t !== MonitorLabel::TAG_USER));

        try {
            $label->saveOrPrune();
        } catch (UniqueConstraintViolationException) {
            // Outra request concorrente já criou a linha enquanto esta
            // calculava `$tags` — inofensivo: não há transição subsequente
            // pro mesmo Monitor hoje (ver docblock acima) pra reaplicar.
        }
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
                    // $data é AsArrayObject (ArrayAccess), não array puro -
                    // array_key_exists() não aceita ArrayAccess, isset() sim.
                    $newlyAuthenticated = ! isset($data['user_id']);
                    $data['user_id'] = Auth::id();

                    if ($newlyAuthenticated) {
                        $this->maybeAutoHumanClassify($user->id);
                    }
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
                VisitRecorder::record($user, $path, $isScraper, $ip);

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

        if (isset($data['user_id'])) {
            $this->maybeAutoHumanClassify($user->id);
        }

        $user->recordHit($path, $notFound);
        $this->recordIpIfChanged($user, $ip);
        VisitRecorder::record($user, $path, $isScraper, $ip);

        $response->headers->setCookie(cookie(
            config('monitor.remember_cookie', 'monitor_id_token'),
            $rememberToken,
            config('monitor.remember_cookie_days', 1825) * 1440
        ));
    }
}
