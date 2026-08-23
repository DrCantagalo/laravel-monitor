<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor as MonitorModel;

class Monitor
{
    /**
     * Chaves internas do Monitor que `tag()` nunca pode sobrescrever, mesmo
     * que o host app mande um par com esse nome. Vivia como
     * `MonitorController::PROTECTED_DATA_KEYS` até v0.2.0 — movida pra cá
     * (constante pública) porque o controller foi removido junto com as
     * rotas públicas de visitante (ver CHANGELOG v0.2.0).
     */
    public const PROTECTED_DATA_KEYS = [
        'sessions', 'ips', 'visits', 'page', 'id-token', 'ua', 'user_id',
    ];

    /**
     * Marca a request atual como "não rastrear": `MonitorMethod` vai
     * ignorar essa passagem pela sessão (não conta como visita nem
     * reescreve `data` do Monitor). A flag é lida e apagada por
     * `SessionVisitorTracker::track()` na próxima vez que o middleware
     * rodar a "lógica de volta" para esta sessão - chame antes de
     * responder a request que não deve ser rastreada (ex: endpoints
     * AJAX/API internos dentro do grupo `web`).
     */
    public function skipTracking(): void
    {
        session()->put(config('monitor.skip_session_key', 'avoid_monitor'), true);
    }

    /**
     * Equivalente server-side do antigo `GET /monitor/update-data`
     * (removido em v0.2.0 — ver CHANGELOG). Grava pares chave/valor
     * arbitrários em `data` do Monitor da sessão atual — base de
     * segmentação/tags (idioma, preferências etc.), não é CRM/lead ainda.
     * Chaves internas usadas pelo MonitorMethod (PROTECTED_DATA_KEYS) são
     * ignoradas silenciosamente para não corromper o tracking.
     *
     * Mesmo contrato de antes, adaptado pra chamada direta (sem HTTP): não
     * lança exceção quando não há sessão de monitor ativa ou payload vazio
     * — só retorna `false`, e o caller confere o bool em vez de parsear um
     * erro JSON.
     */
    public function tag(array $data): bool
    {
        $monitorId = session('monitor_id');

        if (! $monitorId) {
            return false;
        }

        $user = MonitorModel::find($monitorId);

        if (! $user) {
            return false;
        }

        if (empty($data)) {
            return false;
        }

        $current = $user->data;

        foreach ($data as $key => $value) {
            if (in_array($key, self::PROTECTED_DATA_KEYS, true)) {
                continue;
            }

            $current[$key] = $value;
        }

        $user->data = $current;
        $user->save();

        return true;
    }

    /**
     * Equivalente server-side do antigo `GET /monitor/remember-me`
     * (removido em v0.2.0 — ver CHANGELOG). Lê o cookie de remember-me da
     * request atual (`request()->cookie(...)`, sem round-trip HTTP) e
     * busca o Monitor correspondente.
     *
     * Diferente do endpoint antigo (que sempre respondia `success: true`
     * bastando o cookie existir, mesmo sem bater com nenhuma linha —
     * quem de fato validava era `SessionVisitorTracker::track()` mais
     * adiante, silenciosamente), aqui o lookup roda na hora e o retorno
     * reflete se o visitante foi realmente reconhecido. Quando encontrado,
     * seta `session(['remember_me' => $token])` — mesmo sinal que o
     * middleware `MonitorMethod` já consumia antes na "lógica de volta"
     * desta mesma request (`SessionVisitorTracker::track()` refaz esse
     * lookup ao consumir a flag; redundante mas inofensivo), então o
     * comportamento de reconexão do visitante continua idêntico ao
     * anterior.
     */
    public function recognize(): bool
    {
        $token = request()->cookie(config('monitor.remember_cookie', 'monitor_id_token'));

        if (! $token) {
            return false;
        }

        $user = MonitorModel::where('data->id-token', $token)->first();

        if (! $user) {
            return false;
        }

        session(['remember_me' => $token]);

        return true;
    }
}
