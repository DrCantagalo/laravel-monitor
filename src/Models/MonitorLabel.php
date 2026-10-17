<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * laravel-monitor 295 (v0.61.0): tag reservada, gerenciada
     * automaticamente pelo pacote (`SessionVisitorTracker::
     * maybeAutoHumanClassify()`/`syncUserTag()`) conforme o Monitor tem ou
     * não `data.user_id` preenchido — nunca gravável/removível
     * manualmente. `MonitorController::setMonitorTags()` rejeita (422)
     * qualquer tentativa de `op=add`/`op=remove` com este valor. Permite
     * distinguir, na listagem por IP, quem é usuário autenticado (tem a
     * tag `user` em algum dos seus Monitors) de guest (nenhum tem).
     */
    public const TAG_USER = 'user';

    /**
     * laravel-monitor 369 (v0.72.0): sintaxe reservada do filtro `tag` de
     * `getVisitorsByIp`/`getBlockedIps`/`getMonitorQueue` — `*` ("tem
     * alguma tag custom") e `-` ("não tem nenhuma"), ver
     * `parseTagFilter()`. Qualquer tag começando com `-` (ex: `-foo`, que
     * no filtro significa "não tem a tag foo") também é reservada. Nenhum
     * dos três pode ser salvo como tag de verdade — `normalizeTags()`
     * descarta os três abaixo, então eles nunca entram em
     * `MonitorLabel.tags`.
     */
    public const TAG_FILTER_ANY = '*';

    public const TAG_FILTER_NONE = '-';

    /**
     * laravel-monitor 377: checa se `$source` (coluna `monitor_labels.
     * source`) pertence a uma das categorias "protegidas" contra uma
     * escrita `source=ai` subsequente — `manual` (`setMonitorKind` sem
     * `source=ai`), `auth` (`SessionVisitorTracker::
     * maybeAutoHumanClassify()`, auto-classificação no login) ou qualquer
     * `verified*` (`Support\Monitor::markAsHuman()`, prefixo porque o
     * sufixo carrega o método de verificação, ex: `verified-turnstile`).
     * `str_starts_with()` de propósito (NUNCA `===`) pra cobrir qualquer
     * sufixo de `verified-{method}`, não só o `verified` plano.
     *
     * MESMA checagem usada tanto por `MonitorController::setMonitorKind`
     * (guarda contra `source=ai` sobrescrever) quanto por
     * `Support\Monitor::markAsHuman()` (guarda "silent no-op" sobre
     * `manual`/`auth`/`verified*` — ver docblock lá pra regra completa de
     * precedência `manual > auth > verified-* > ai`). Extraída pra cá (em
     * vez de duplicada nos dois call sites) depois que a 303/v0.62.0
     * deixou o guard do controller checando só `=== 'manual'` — o que
     * deixava `source=ai` sobrescrever silenciosamente um `kind` gravado
     * por `auth` (ou, depois da 377, por `verified*`), ao contrário do que
     * o README sempre documentou ("a source=ai write never overwrites a
     * kind already set by ANY other source").
     *
     * IMPORTANTE: só tem sentido olhar `source` quando o label já tem
     * `kind` definido — uma linha só com `tags`/`note` (`kind=null`, ex:
     * criada por `addTag()`) ainda tem `source=manual` por DEFAULT DO
     * BANCO (migration `2026_09_30_000000_move_ip_labels_to_monitor_labels_
     * table`, coluna nunca ficou sem default), mas isso não representa
     * nenhuma classificação manual de verdade. Os dois call sites checam
     * `kind !== null` ANTES de chamar este método — este método em si não
     * repete essa checagem (não tem acesso ao `kind` aqui, só ao valor de
     * `source` já extraído pelo caller).
     */
    public static function isProtectedSource(?string $source): bool
    {
        return $source === 'manual'
            || $source === 'auth'
            || str_starts_with((string) $source, 'verified');
    }

    /**
     * trim + lowercase + dedupe (preservando a ordem de primeira
     * aparição) + descarta vazias/longas demais/reservadas pro filtro de
     * tag (`*`, `-`, qualquer `-foo`) + limita a quantidade. Usado tanto
     * pela escrita direta quanto pelo merge de tags.
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

            if ($tag === self::TAG_FILTER_ANY || str_starts_with($tag, self::TAG_FILTER_NONE)) {
                continue;
            }

            if (! in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return array_slice($normalized, 0, self::MAX_TAGS);
    }

    /**
     * Parseia o valor do parâmetro `tag` dos endpoints de listagem
     * (`getVisitorsByIp`/`getBlockedIps`/`getMonitorQueue`) — não confundir
     * com uma tag de verdade salva em `MonitorLabel.tags` (ver
     * `normalizeTags()`, que rejeita estes três valores como tag). Espera
     * `$value` já trim/lowercase (ver os callers em `MonitorController`).
     *
     * laravel-monitor 369 (v0.72.0): `*` = tem pelo menos uma tag CUSTOM
     * (qualquer tag exceto a reservada `TAG_USER`); `-` = não tem nenhuma;
     * `-foo` = não tem a tag `foo` (vale também `-user`); qualquer outro
     * valor = tag exata, igual sempre foi (incluindo `user`).
     *
     * @return array{mode: 'exact'|'any'|'none'|'exclude', value: ?string}
     */
    public static function parseTagFilter(string $value): array
    {
        if ($value === self::TAG_FILTER_ANY) {
            return ['mode' => 'any', 'value' => null];
        }

        if ($value === self::TAG_FILTER_NONE) {
            return ['mode' => 'none', 'value' => null];
        }

        if (str_starts_with($value, self::TAG_FILTER_NONE)) {
            return ['mode' => 'exclude', 'value' => mb_substr($value, 1)];
        }

        return ['mode' => 'exact', 'value' => $value];
    }

    /**
     * Restringe $query (Eloquent ou Query Builder, tanto faz — ambos
     * expõem a mesma API `whereJsonLength`/`orWhere`/`whereJsonDoesntContain`)
     * a linhas cujo `$column` (um array JSON de tags, ex: `monitor_labels.
     * tags`) tem pelo menos uma tag CUSTOM — qualquer tag exceto a
     * reservada `TAG_USER`. Bloco de montagem do filtro `tag=*`/`tag=-`
     * (laravel-monitor 369/v0.72.0).
     *
     * `tags` nunca tem `user` mais de uma vez (`normalizeTags()` já
     * dedupe), então: tamanho > 1 já garante sozinho que existe tag custom
     * (só uma das posições pode ser `user`); com tamanho == 1, só é custom
     * se essa única tag não for `user`. Evita montar isso com
     * `JSON_LENGTH`/`JSON_CONTAINS` crus (funções só do MySQL, quebrariam
     * os testes em SQLite) — os métodos do query builder do Laravel
     * traduzem pra gramática certa em cada driver.
     */
    public static function constrainToCustomTagExists($query, string $column)
    {
        return $query->where(function ($q) use ($column) {
            $q->whereJsonLength($column, '>', 1)
                ->orWhere(function ($q2) use ($column) {
                    $q2->whereJsonLength($column, '=', 1)
                        ->whereJsonDoesntContain($column, self::TAG_USER);
                });
        });
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

    /**
     * laravel-monitor 295 (v0.61.0): "salva, ou apaga se ficou vazia" —
     * antes só existia como `MonitorController::saveOrPruneLabel()`
     * (protected, só pros write paths HTTP do controller); movido pro
     * model e exposto publicamente pra `SessionVisitorTracker::
     * syncUserTag()` (escrita interna, fora do controller) poder usar a
     * mesma regra sem duplicá-la. `saveOrPruneLabel()` no controller
     * agora só delega pra aqui.
     */
    public function saveOrPrune(): void
    {
        if ($this->isEmpty()) {
            if ($this->exists) {
                $this->delete();
            }

            return;
        }

        $this->save();
    }

    /**
     * laravel-monitor 320/331: mescla `$tags` (já sanitizadas - ver
     * `Support\Monitor::sanitizeCustomTags()`) nas tags existentes do
     * `MonitorLabel` do Monitor `$monitorId`. Extraído de
     * `Support\Monitor::applyCustomTags()` (que agora só delega pra cá)
     * pra ser o MESMO caminho usado por `SessionVisitorTracker`/
     * `AnonymousVisitorTracker::applyPendingCustomTags()` (laravel-monitor
     * 331) ao aplicar as tags que `Monitor::addTags()` deixou pendentes
     * (`PendingCustomTags`) enquanto o Monitor da request ainda não
     * existia - sem duplicar a lógica de merge em dois lugares.
     *
     * `firstOrNew` + `lockForUpdate()` dentro de uma transaction, mesmo
     * padrão de concorrência de `MonitorController::setMonitorTags()`
     * (relevante pra `addTagForUser()`, que pode rodar duas vezes pra
     * mesma compra). `MAX_TAGS` pode descartar alguma tag do pedido
     * (merge com as já existentes) - log, nunca erro. Nunca toca
     * `kind`/`classified_at`: uma linha nova fica NÃO classificada.
     *
     * @param  array<int, string>  $tags
     */
    public static function mergeCustomTags(int $monitorId, array $tags): bool
    {
        return DB::transaction(function () use ($monitorId, $tags) {
            $label = self::where('monitor_id', $monitorId)->lockForUpdate()->first()
                ?? new self(['monitor_id' => $monitorId, 'tags' => []]);

            $existing = $label->tags ?? [];
            $merged = self::normalizeTags([...$existing, ...$tags]);
            $dropped = array_diff($tags, $merged);

            if (! empty($dropped)) {
                Log::warning('[laravel-monitor] Monitor::addTag(s) descartou tag(s) por exceder MAX_TAGS.', [
                    'monitor_id' => $monitorId,
                    'dropped' => array_values($dropped),
                ]);
            }

            $label->tags = $merged;
            $label->saveOrPrune();

            return true;
        });
    }
}
