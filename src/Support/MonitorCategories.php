<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * laravel-monitor 308 (v0.63.0): fonte única das 5 categorias de Monitor
 * (`human_user`/`human_guest`/`bot`/`flagged`/`unclassified`) — extraído de
 * `MonitorController` (onde a regra nasceu na task 284/v0.59.0, const
 * `MONITOR_CATEGORIES`/método `categoryForMonitor()`/query
 * `aggregateMonitorsByKind()`, que alimenta `getData.monitors_by_kind`)
 * pra poder ser reusado por `Support\DataPruner::pruneByCategory()` (a
 * nova action HTTP `clearData` filtrada por categoria/`monitor:prune
 * --category`) SEM reimplementar o predicado — exigência explícita da
 * task 308: a categoria usada pra decidir o que apagar tem que ser
 * EXATAMENTE a mesma que decide a contagem em `monitors_by_kind`.
 *
 * `MonitorController` continua com `MONITOR_CATEGORIES`/
 * `categoryForMonitor()` (usados por `timelineMonitorsNew()`/
 * `timelineVisits()`, getTimeline) como wrappers finos que delegam pra cá
 * — nunca duas implementações divergindo.
 *
 * Duas formas de usar a mesma regra:
 * - Por Monitor já carregado em PHP (`categoryForMonitor()`, usado pelas
 *   séries diárias do getTimeline, que processam linha a linha via
 *   `chunk()`).
 * - Como predicado SQL puro (`sqlPredicateForCategory()`), pra embutir
 *   num `WHERE`/`CASE WHEN` sem carregar nada em PHP — usado por
 *   `aggregateMonitorsByKind()` (uma linha agregada só) e por
 *   `DataPruner::pruneByCategory()` (delete/contagem em massa).
 *
 * `unclassified` aqui NUNCA considera idade — inclui o bucket `new` que
 * `aggregateMonitorsByKind()`/`monitorsByKind()` (getData) separam só
 * naquele agregado específico (carência da fila de triagem IA,
 * `aiTriageCutoff()`). Decisão de produto deliberada da task 308: pra
 * fins de categorização/cleanup, um Monitor "novo" e um "unclassified" de
 * verdade são o MESMO grupo (sem `kind`, não flagado), só diferem por
 * idade - uma distinção que só faz sentido pro agregado "agora" do
 * dashboard, não pra decidir o que apagar.
 */
class MonitorCategories
{
    public const ALL = ['human_user', 'human_guest', 'bot', 'flagged', 'unclassified'];

    /**
     * `$categories` é considerado "todas as categorias" (equivalente a
     * nenhum filtro) quando, depois de deduplicado, cobre as 5 de
     * `self::ALL` — usado por `DataPruner::pruneByCategory()` pra decidir
     * se o filtro é "sem restrição" (comportamento antigo
     * `clearData`/`pruneData`, inclusive tocando `monitor_ip_stats`/
     * `monitor_visits`) ou um subconjunto de fato (só `monitors`).
     */
    public static function isAllCategories(array $categories): bool
    {
        return count(array_unique($categories)) === count(self::ALL);
    }

    /**
     * Categoria de um Monitor a partir do `kind` já resolvido (`null`/
     * `'human'`/`'bot'` — qualquer outro valor deve ser normalizado pra
     * `null` por quem chama) e dos dois booleanos pré-calculados pelo
     * chamador (ver `idSets()`/`flaggedMonitorIds()`/
     * `monitorIdsWithUserId()` — nunca calculados aqui dentro, pra não
     * rodar uma query por Monitor).
     */
    public static function categoryForMonitor(?string $kind, bool $hasUserId, bool $isFlagged): string
    {
        if ($kind === 'bot') {
            return 'bot';
        }

        if ($kind === 'human') {
            return $hasUserId ? 'human_user' : 'human_guest';
        }

        return $isFlagged ? 'flagged' : 'unclassified';
    }

    /**
     * IDs de Monitor flagado/bloqueado (visto em pelo menos um IP com
     * `monitor_ip_stats.flagged=true` OU com bloqueio vigente,
     * `BlockedIp::active()`) e IDs de Monitor com `user_id` presente em
     * `data` — os dois conjuntos que `categoryForMonitor()`/
     * `sqlPredicateForCategory()` dependem, calculados UMA VEZ por
     * chamada, nunca por linha/Monitor.
     *
     * Fail-open por conjunto: uma falha aqui (ex: `monitor_visit_ips`
     * ausente) não pode derrubar quem chama — trata como conjunto vazio
     * (nenhum Monitor flagado / nenhum com user_id), a degradação mais
     * conservadora (tudo que seria `flagged` cai em `unclassified`; tudo
     * que seria `human_user` cai em `human_guest`).
     *
     * @return array{0: Collection<int,int>, 1: Collection<int,int>} [$flaggedMonitorIds, $monitorIdsWithUserId]
     */
    public static function idSets(): array
    {
        try {
            $flaggedIds = self::flaggedMonitorIds();
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] falha ao calcular Monitors flagados pras categorias — tratando como nenhum. Erro original: '.$e->getMessage());
            $flaggedIds = collect();
        }

        try {
            $userIds = self::monitorIdsWithUserId();
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] falha ao calcular Monitors com user_id pras categorias — tratando como nenhum. Erro original: '.$e->getMessage());
            $userIds = collect();
        }

        return [$flaggedIds, $userIds];
    }

    /**
     * @return Collection<int,int>
     */
    public static function flaggedMonitorIds(): Collection
    {
        return self::monitorIdsAtFlaggedIps()->merge(self::monitorIdsAtBlockedIps())->unique()->values();
    }

    /**
     * @return Collection<int,int>
     */
    public static function monitorIdsAtFlaggedIps(): Collection
    {
        return self::monitorIdsForIps(IpStat::where('flagged', true)->pluck('ip'));
    }

    /**
     * @return Collection<int,int>
     */
    public static function monitorIdsAtBlockedIps(): Collection
    {
        return self::monitorIdsForIps(BlockedIp::active()->pluck('ip'));
    }

    /**
     * @param  Collection<int,string>  $ips
     * @return Collection<int,int>
     */
    public static function monitorIdsForIps(Collection $ips): Collection
    {
        if ($ips->isEmpty()) {
            return collect();
        }

        return DB::table('monitor_visit_ips')->whereIn('ip', $ips)->distinct()->pluck('monitor_id');
    }

    /**
     * @return Collection<int,int>
     */
    public static function monitorIdsWithUserId(): Collection
    {
        $query = DB::table('monitors')->select('id');

        if ($query->getConnection()->getDriverName() === 'mysql') {
            $query->whereNotNull('monitors_user_id');
        } else {
            $query->whereNotNull('data->user_id');
        }

        return $query->pluck('id');
    }

    /**
     * Converte uma collection de IDs numa lista "1,2,3" segura pra embutir
     * direto num `IN (...)` de SQL raw — cast pra int em cada item (nunca
     * interpola um valor não confiável) e `-1` (nenhum Monitor tem esse
     * id) quando a collection está vazia, pra manter `IN (...)`
     * sintaticamente válido sem um `CASE WHEN` extra só pra lista vazia.
     *
     * @param  Collection<int,int>  $ids
     */
    public static function sqlIntList(Collection $ids): string
    {
        if ($ids->isEmpty()) {
            return '-1';
        }

        return $ids->map(fn ($id) => (int) $id)->implode(',');
    }

    /**
     * Fragmento SQL booleano: sem `kind` reconhecido (`NULL`, ou,
     * defensivamente, qualquer valor fora de `human`/`bot`). `$kindSql` é
     * a expressão SQL do `kind` já resolvido — a coluna `monitor_labels.kind`
     * com o alias usado pelo chamador (ex: `ml.kind`), ou a string literal
     * `NULL` quando a tabela ainda não existe/não foi joinada (mesmo
     * fallback fail-open que `aggregateMonitorsByKind()`/
     * `timelineMonitorsNew()` já usam).
     */
    public static function noKindSql(string $kindSql): string
    {
        return "({$kindSql} IS NULL OR {$kindSql} NOT IN ('human', 'bot'))";
    }

    public static function isFlaggedSql(string $idColumn, Collection $flaggedIds): string
    {
        return "{$idColumn} IN (".self::sqlIntList($flaggedIds).')';
    }

    public static function notFlaggedSql(string $idColumn, Collection $flaggedIds): string
    {
        return "{$idColumn} NOT IN (".self::sqlIntList($flaggedIds).')';
    }

    public static function hasUserIdSql(string $idColumn, Collection $userIds): string
    {
        return "{$idColumn} IN (".self::sqlIntList($userIds).')';
    }

    public static function noUserIdSql(string $idColumn, Collection $userIds): string
    {
        return "{$idColumn} NOT IN (".self::sqlIntList($userIds).')';
    }

    /**
     * Predicado SQL booleano (sem parênteses externos) que casa as linhas
     * de `monitors` de UMA categoria de `self::ALL` — a MESMA regra de
     * `categoryForMonitor()`, em SQL, pronta pra embutir num `WHERE`
     * (`DataPruner::pruneByCategory()`) ou num `SUM(CASE WHEN ...)`
     * (`MonitorController::aggregateMonitorsByKind()`). `$idColumn`:
     * expressão SQL do `id` de `monitors` (com alias do chamador, ex:
     * `m.id`).
     */
    public static function sqlPredicateForCategory(string $category, string $kindSql, string $idColumn, Collection $flaggedIds, Collection $userIds): string
    {
        $noKind = self::noKindSql($kindSql);

        return match ($category) {
            'bot' => "{$kindSql} = 'bot'",
            'human_user' => "{$kindSql} = 'human' AND ".self::hasUserIdSql($idColumn, $userIds),
            'human_guest' => "{$kindSql} = 'human' AND ".self::noUserIdSql($idColumn, $userIds),
            'flagged' => "{$noKind} AND ".self::isFlaggedSql($idColumn, $flaggedIds),
            'unclassified' => "{$noKind} AND ".self::notFlaggedSql($idColumn, $flaggedIds),
            default => throw new \InvalidArgumentException("Unknown monitor category: {$category}"),
        };
    }
}
