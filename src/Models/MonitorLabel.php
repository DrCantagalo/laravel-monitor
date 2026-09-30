<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * laravel-monitor 258 (v0.53.0): classificação bot/human + tags custom +
 * note — migrou de `monitor_ip_labels` (uma linha por IP, classe
 * `MonitorIpLabel` até a 0.52.0) pra cá: uma linha por `Monitor`
 * (visitante), FK `cascadeOnDelete`. Motivo: o IP não é o visitante
 * (CGNAT, IP reatribuído, proxy residencial) e a classificação é uma
 * conclusão tirada dos DADOS do monitor (UA, hits, paths) — sem o
 * monitor não deve sobrar rótulo (nem `source=manual`, que antes
 * sobrevivia indefinidamente a um IP nunca mais visto). O rótulo por IP
 * (usado por `getVisitorsByIp`/`getBlockedIps`/`getIpMonitors`) agora é
 * DERIVADO na hora a partir dos Monitors vistos naquele IP, nunca gravado
 * — ver `MonitorController::derivedLabelsForIps()` e o README "IP
 * classification".
 *
 * Uma linha só existe quando há algo a guardar: sem `kind`, sem `tags` e
 * sem `note`, a linha é apagada (`todo Monitor começa indefinido = sem
 * linha`, ver `isEmpty()`/`MonitorController::saveOrPruneLabel()`).
 */
class MonitorLabel extends Model
{
    protected $table = 'monitor_labels';

    protected $fillable = [
        'monitor_id', 'kind', 'tags', 'note', 'source', 'classified_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'classified_at' => 'datetime',
    ];

    public const KINDS = ['bot', 'human'];

    /**
     * Limites "razoáveis" documentados no README — protegem contra um tag
     * autocomplete/lote virando um vetor de abuso (milhares de tags por
     * Monitor, ou uma tag de vários KB), sem impor nenhuma regra de
     * produto real além disso.
     */
    public const MAX_TAGS = 20;

    public const MAX_TAG_LENGTH = 40;

    /**
     * trim + lowercase + dedupe (preservando a ordem de primeira
     * aparição) + descarta vazias/longas demais + limita a quantidade.
     * Usado tanto pela escrita direta quanto pelo merge de tags.
     *
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    public static function normalizeTags(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = mb_strtolower(trim($tag));

            if ($tag === '' || mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                continue;
            }

            if (! in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return array_slice($normalized, 0, self::MAX_TAGS);
    }

    /**
     * Uma linha "vazia" (sem kind, sem tags, sem note) não deve ser
     * guardada — todo Monitor começa indefinido por ausência de linha,
     * não por uma linha com tudo nulo.
     */
    public function isEmpty(): bool
    {
        return $this->kind === null
            && empty($this->tags)
            && ($this->note === null || $this->note === '');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
