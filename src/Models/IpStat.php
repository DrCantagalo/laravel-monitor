<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class IpStat extends Model
{
    protected $table = 'monitor_ip_stats';

    protected $fillable = [
        'ip', 'visit_count', 'first_seen', 'last_seen', 'flagged', 'flagged_signals', 'safe',
    ];

    protected $casts = [
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
        'flagged' => 'boolean',
        'flagged_signals' => 'array',
        'safe' => 'boolean',
    ];

    /**
     * Chamado pelos dois trackers (`AnonymousVisitorTracker`/
     * `SessionVisitorTracker`) a cada request rastreada, com o mesmo
     * `$flagged`/`$signals` que eles já calcularam via
     * `ScraperSignalDetector` pra gravar em `data.flags.*` no `Monitor`.
     * `flagged`/`flagged_signals` refletem sempre o request mais recente
     * desse IP, não um OR acumulado — mesma semântica de
     * `data.flags.scraper` no Monitor. `safe` nunca é tocado aqui (só por
     * markIpSafe/unmarkIpSafe no MonitorController) - continua valendo
     * mesmo que o request mais recente desse IP tenha voltado a marcar
     * `flagged = true` (ver buildVisitorsResult, que é quem de fato
     * respeita `safe` ao decidir o que expor como fila de revisão).
     *
     * Task 90: até a 0.10.0 este método fazia `where('ip',
     * $ip)->first()` seguido de `create()`/`save()` — não-atômico. Duas
     * requests do mesmo IP em paralelo (comum em bots martelando um
     * endpoint) podiam ambas ver `null` no `first()` e ambas tentarem
     * `create()`, e a segunda violava a constraint UNIQUE de `ip` e
     * lançava `QueryException` não capturada pro visitante/bot original
     * (500), confirmado em produção (ver bugs/laravel-monitor.md).
     * Trocado por `upsert()` do query builder — uma query só, sem
     * SELECT+INSERT/UPDATE separados pra correr atrás um do outro (`ON
     * DUPLICATE KEY UPDATE` no MySQL, `ON CONFLICT` no SQLite/Postgres) —
     * mesmo padrão já usado por `MonitorMethod::recordBlockedAttempt()`
     * pra `monitor_block_results`. `first_seen`/`created_at` só entram no
     * array de insert (nunca no de update), preservando o valor original
     * em conflitos; `safe` fica de fora dos dois arrays de propósito —
     * usa o default `false` da coluna no insert, e nunca é tocado num
     * conflito (só markIpSafe/unmarkIpSafe mexem nele). Bypassa os casts
     * do Eloquent (upsert() é query builder puro), por isso o
     * `json_encode` manual de `flagged_signals` e o cast de `$flagged`
     * pra int abaixo.
     */
    public static function recordVisit(string $ip, bool $flagged, array $signals): void
    {
        $now = now();

        DB::table('monitor_ip_stats')->upsert(
            [
                'ip' => $ip,
                'visit_count' => 1,
                'first_seen' => $now,
                'last_seen' => $now,
                'flagged' => (int) $flagged,
                'flagged_signals' => json_encode($signals),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['ip'],
            [
                'visit_count' => DB::raw('visit_count + 1'),
                'last_seen' => $now,
                'flagged' => (int) $flagged,
                'flagged_signals' => json_encode($signals),
                'updated_at' => $now,
            ]
        );
    }
}
