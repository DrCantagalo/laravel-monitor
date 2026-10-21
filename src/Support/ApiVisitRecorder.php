<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor as MonitorModel;
use Drcantagalo\LaravelMonitor\Models\MonitorVisit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * laravel-monitor 400: equivalente de `VisitRecorder` pro grupo `api`
 * (`MonitorApiMethod`) — grava a jornada de uma "visita" em
 * `monitor_visits`, mesma tabela/shape de sempre (`paths`/`scraper`/`ip`),
 * mas SEM sessão pra guardar "qual visita está aberta" (API é stateless
 * por natureza: nenhum cookie/sessão sobrevive entre chamadas do mesmo
 * token/IP).
 *
 * Em vez disso, o boundary é por TEMPO: cada Monitor do grupo `api` já é,
 * por construção, 1:1 com um token OU um IP (`ApiTokenVisitorTracker`
 * resolve/cria um Monitor por `token_id`; sem token, o fallback reusa
 * `AnonymousVisitorTracker`, que já é 1 Monitor por IP) — então "a última
 * visita deste monitor_id" É "a última visita deste token/IP", sem
 * precisar repetir o token/IP aqui. Se a última visita deste Monitor foi
 * atualizada há mais de `api_visit_gap_minutes` minutos, abre uma visita
 * nova; senão, anexa (mesmo teto de `visit_max_paths` do tracker com
 * sessão).
 *
 * `scraper` sempre `false` aqui: a classificação de um Monitor do grupo
 * `api` é sempre `kind=bot,source=auth` (token) OU decidida pelo
 * `ScraperSignalDetector` via `AnonymousVisitorTracker` (fallback por IP,
 * já registrado em `data.flags.scraper` daquele Monitor) — duplicar o
 * sinal na visita em si não muda nenhuma decisão do pacote hoje.
 */
class ApiVisitRecorder
{
    public static function record(MonitorModel $monitor, string $path, ?string $ip = null): void
    {
        if (! config('monitor.track_visits', true)) {
            return;
        }

        try {
            $gapMinutes = max(1, (int) config('monitor.api_visit_gap_minutes', 30));

            $visit = MonitorVisit::where('monitor_id', $monitor->id)
                ->orderByDesc('id')
                ->first();

            $withinGap = $visit
                && $visit->updated_at !== null
                && $visit->updated_at->diffInMinutes(now()) <= $gapMinutes;

            if ($withinGap) {
                $paths = (array) $visit->paths;

                if (count($paths) < max(1, (int) config('monitor.visit_max_paths', 200))) {
                    $paths[] = $path;
                    $visit->paths = $paths;
                }

                // touch() grava os atributos sujos (paths) E atualiza
                // `updated_at` — mesmo padrão de `VisitRecorder::record()`.
                $visit->touch();

                return;
            }

            MonitorVisit::create([
                'monitor_id' => $monitor->id,
                'paths' => [$path],
                'scraper' => false,
                'ip' => $ip,
            ]);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_visits não encontrada ao gravar visita de API — rode `php artisan migrate` ou `php artisan monitor:update`. Erro original: '.$e->getMessage());
        }
    }
}
