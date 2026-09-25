<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * laravel-monitor 239 (v0.49.0): classificação bot/human + tags custom por
 * IP — anotação pura, sem nenhum efeito em bloqueio/scraper/triagem (ver
 * README "IP classification"). Tabela própria (não coluna em
 * `monitor_ip_stats`): o `DataPruner`/`clearData` apagam `IpStat` por
 * idade/truncate, e a anotação do usuário não pode sumir junto — nenhum
 * dos dois toca `monitor_ip_labels`.
 *
 * Uma linha só existe quando há algo a guardar: sem `kind`, sem `tags` e
 * sem `note`, a linha é apagada (`todo IP começa indefinido = sem linha`,
 * ver `isEmpty()`/`MonitorController::pruneLabelIfEmpty()`).
 */
class MonitorIpLabel extends Model
{
    protected $table = 'monitor_ip_labels';

    protected $fillable = [
        'ip', 'kind', 'tags', 'note', 'source', 'classified_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'classified_at' => 'datetime',
    ];

    public const KINDS = ['bot', 'human'];

    /**
     * Limites "razoáveis" documentados no README — protegem contra um tag
     * autocomplete/lote virando um vetor de abuso (milhares de tags por
     * IP, ou uma tag de vários KB), sem impor nenhuma regra de produto
     * real além disso.
     */
    public const MAX_TAGS = 20;

    public const MAX_TAG_LENGTH = 40;

    /**
     * trim + lowercase + dedupe (preservando a ordem de primeira
     * aparição) + descarta vazias/longas demais + limita a quantidade.
     * Usado tanto pela escrita "replace" (`setIpLabels`) quanto pelo
     * merge de `setIpTags`.
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
     * guardada — todo IP começa indefinido por ausência de linha, não por
     * uma linha com tudo nulo.
     */
    public function isEmpty(): bool
    {
        return $this->kind === null
            && empty($this->tags)
            && ($this->note === null || $this->note === '');
    }
}
