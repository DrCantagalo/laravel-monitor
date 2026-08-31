<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

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
     */
    public static function recordVisit(string $ip, bool $flagged, array $signals): void
    {
        $stat = static::where('ip', $ip)->first();

        if ($stat) {
            $stat->visit_count++;
            $stat->last_seen = now();
            $stat->flagged = $flagged;
            $stat->flagged_signals = $signals;
            $stat->save();

            return;
        }

        static::create([
            'ip' => $ip,
            'visit_count' => 1,
            'first_seen' => now(),
            'last_seen' => now(),
            'flagged' => $flagged,
            'flagged_signals' => $signals,
        ]);
    }
}
