<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor as MonitorModel;
use Drcantagalo\LaravelMonitor\Models\MonitorVisit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Grava a jornada (paths em ordem de acesso) de uma visita em
 * `monitor_visits`. Visita = sessão PHP, mas a chave NÃO é o `session_id`:
 * o Laravel regenera o id da sessão no login (`session()->migrate(true)`),
 * o que partiria a visita ao meio bem no ponto em que um funil mais
 * importa. Em vez disso o id da visita fica DENTRO da sessão
 * (`monitor_visit_id`, mesmo padrão do `monitor_id`), que sobrevive à
 * regeneração — e uma sessão realmente nova simplesmente não tem essa
 * chave, criando uma visita nova.
 *
 * Só o `SessionVisitorTracker` chama isto: o tracker anônimo (API/bots/
 * scrapers, sem sessão) não tem o que agrupar numa "visita".
 */
class VisitRecorder
{
    public const SESSION_KEY = 'monitor_visit_id';

    /**
     * `$isScraper` é sticky-true na visita (`scraper = scraper || $isScraper`):
     * a visita é gravada mesmo quando flagged, só marcada — descartá-la
     * perderia a jornada de um humano mal classificado sem volta.
     *
     * A visita é buscada por `id` E `monitor_id`: a sessão pode carregar o
     * id de uma visita de OUTRO monitor (ramo remember-me do tracker apaga
     * o monitor efêmero da sessão e adota o monitor do cookie) ou de uma
     * visita já apagada pelo prune — nesses casos cria uma visita nova em
     * vez de anexar no lugar errado.
     *
     * Leitura-modificação-gravação de uma linha pequena (limitada por
     * `visit_max_paths`): requests paralelas da MESMA sessão podem perder um
     * passo. Aceito de propósito — se incomodar, `JSON_ARRAY_APPEND` no
     * MySQL resolve numa query só.
     *
     * Fail-open (mesmo padrão dos demais gravadores do pacote): tabela
     * ainda não migrada não pode derrubar o site hospedeiro.
     */
    public static function record(MonitorModel $monitor, string $path, bool $isScraper): void
    {
        if (! config('monitor.track_visits', true)) {
            return;
        }

        try {
            $visitId = session(self::SESSION_KEY);

            $visit = $visitId
                ? MonitorVisit::where('id', $visitId)->where('monitor_id', $monitor->id)->first()
                : null;

            if ($visit) {
                $paths = (array) $visit->paths;

                if (count($paths) < max(1, (int) config('monitor.visit_max_paths', 200))) {
                    $paths[] = $path;
                    $visit->paths = $paths;
                }

                if ($isScraper) {
                    $visit->scraper = true;
                }

                // touch() grava os atributos sujos E atualiza `updated_at`
                // (última atividade), inclusive quando o teto já foi
                // atingido e `paths` não mudou.
                $visit->touch();

                return;
            }

            $visit = MonitorVisit::create([
                'monitor_id' => $monitor->id,
                'paths' => [$path],
                'scraper' => $isScraper,
            ]);

            session([self::SESSION_KEY => $visit->id]);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_visits não encontrada — rode `php artisan migrate` ou `php artisan monitor:update`. Erro original: '.$e->getMessage());
        }
    }
}
