<?php

namespace Drcantagalo\LaravelMonitor\Support;

class Monitor
{
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
}
