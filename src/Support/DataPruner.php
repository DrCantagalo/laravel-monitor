<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cleanup parcial de dados de tracking (`Monitor`/`monitor_ip_stats`) mais
 * antigos que um cutoff, opcionalmente restrito aos IPs confirmado-
 * bloqueados (`monitor_blocked_ips`) — extraído de
 * `MonitorController::pruneData()`/`pruneMonitors()` (task 134) pra ficar
 * reusável fora do controller, pelo comando `monitor:prune` além da rota
 * HTTP `pruneData` existente (comportamento/response desta última não
 * mudam).
 *
 * Motivação original do `only_blocked`: manter o histórico de tracking de
 * um IP já confirmado como scraper não tem valor, e o volume de dados
 * cresce rápido por causa deles — sem isso, só existia o cutoff manual por
 * idade (`older_than_days`), sem meio de purgar automaticamente assim que
 * um IP é bloqueado. Desde a task 147, "bloqueado" aqui significa bloqueio
 * VIGENTE (`BlockedIp::active()`) — uma linha de bloqueio temporário já
 * expirado não conta mais, ver docblock de `pruneMonitors()`.
 *
 * **Nota de investigação (task 147)**: uma anomalia foi reportada em
 * produção (`cantagalo.it`, 2026-09-20, laravel-monitor 0.35.0) — um IP
 * com bloqueio temporário já expirado em `monitor_blocked_ips` continuava
 * com uma linha ativa (não purgada) em `monitor_ip_stats`, mesmo com o
 * auto-prune tendo rodado depois da linha existir. Não foi possível
 * reproduzir (sem acesso aos dados de produção). Revisão do código não
 * encontrou nenhum bug de lógica no caminho do `DELETE` em si — o delete
 * de `monitor_ip_stats` em `prune()` roda sempre (sem teto, mesmo depois
 * da task 145), e uma chamada direta e determinística a `prune(0, true)`
 * apaga uma linha elegível corretamente (ver testes). A hipótese mais
 * provável, não confirmada: um mismatch de string entre
 * `monitor_blocked_ips.ip` e `monitor_ip_stats.ip` pro mesmo cliente real
 * (ex: IPv4 vs. representação IPv4-mapped-IPv6, dependendo de qual hop de
 * proxy populou `$request->ip()` em requests diferentes — ver
 * `TrustProxies`) — `whereIn('ip', ...)` compara string exata, então um
 * mismatch assim nunca gera erro, só nunca casa aquela linha específica,
 * indefinidamente. Se o sintoma reaparecer, comparar a string exata de
 * `monitor_blocked_ips.ip` com `monitor_ip_stats.ip` pro mesmo IP (byte a
 * byte, não só visualmente) antes de assumir outra causa.
 */
class DataPruner
{
    protected const CACHE_KEY = 'monitor:data-prune:last-run';

    /**
     * Lock de execução do gatilho automático (task 145) — chave própria,
     * não reusa `CACHE_KEY` (o timestamp precisa continuar existindo depois
     * que o lock já foi liberado). TTL curto (não configurável, é só uma
     * rede de segurança contra um processo morto sem passar pelo `finally`
     * — ex: OOM kill) evita que um lock travado bloqueie o prune pra
     * sempre.
     */
    protected const LOCK_KEY = 'monitor:data-prune:lock';

    protected const LOCK_TTL_SECONDS = 60;

    /**
     * Gatilho automático (laravel-monitor 141), sem depender de cron do
     * consumidor: `monitor:prune` só limpava se alguém configurasse
     * `Schedule::command(...)->hourly()` por conta própria — opt-in,
     * documentado no README mas a maioria nunca faz, então o tracking de
     * IPs já bloqueados crescia pra sempre em silêncio. Mesmo padrão
     * determinístico (timestamp em cache, não probabilístico) já usado por
     * `BlockedIpCleaner::maybeCleanup()` (task 97) — chamado a cada
     * request rastreada pelos dois trackers, só executa de fato se já
     * passou `config('monitor.data_prune_interval_hours')` desde a última
     * vez. Cache key própria, não reusa a do `BlockedIpCleaner` (intervalos
     * configuráveis independentemente). A query agora é indexada
     * (`pruneMonitors()` via `monitor_visit_ips`, ver acima), então rodar
     * isso inline no caminho de request é seguro, sem precisar de queue.
     *
     * Task 145 — duas fragilidades corrigidas aqui: (a) concorrência —
     * como o timestamp só era gravado DEPOIS do prune, duas requests
     * simultâneas com o cache vencido rodavam o prune em paralelo;
     * resolvido com um lock atômico (`Cache::add`) logo no início, liberado
     * em `finally` — quem não obtém o lock apenas retorna, a próxima
     * request tentada de novo. (b) backlog sem teto — ver
     * `pruneMonitors()`. Quando o teto é atingido (`done === false`), o
     * timestamp NÃO é gravado, então a próxima request rastreada considera
     * o prune ainda devido e retoma de onde a query (reconsultada do zero,
     * sem lista congelada) parou — mesmo efeito prático de continuação sem
     * precisar de estado extra além do lock.
     */
    public static function maybeCleanup(): void
    {
        $intervalHours = (int) config('monitor.data_prune_interval_hours', 1);
        $lastRunAt = Cache::get(self::CACHE_KEY);

        if ($lastRunAt !== null && (time() - $lastRunAt) < $intervalHours * 3600) {
            return;
        }

        if (! Cache::add(self::LOCK_KEY, true, self::LOCK_TTL_SECONDS)) {
            return;
        }

        try {
            $maxRows = (int) config('monitor.data_prune_max_rows_per_run', 1000);

            $result = self::prune(0, true, $maxRows);

            if ($result['done']) {
                Cache::forever(self::CACHE_KEY, time());
            }
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /**
     * `$maxRows` é só pro caminho automático (`maybeCleanup()` passa
     * `data_prune_max_rows_per_run`) — o comando `monitor:prune` e a rota
     * HTTP `pruneData` continuam chamando sem esse argumento (`null`),
     * apagando tudo de uma vez como sempre fizeram; são invocações
     * manuais/administrativas, não algo que roda sozinho dentro de toda
     * request de visitante.
     *
     * Task 152 (v0.46.0): além da retenção própria de
     * `pruneVisits()`/`monitor.visits_retention_days` (agora `0`/desligada
     * por padrão — ver comentário da config), quando `$onlyBlocked` é
     * `false` este método TAMBÉM apaga `monitor_visits` mais antigas que o
     * MESMO cutoff de `$olderThanDays` usado pra `monitors`/`monitor_ip_stats`
     * acima — independente do valor de `visits_retention_days`.
     *
     * Motivação: até 0.45.0, `visits_retention_days` default `90` +
     * `maybeCleanup()` chamando `prune(0, true)` a cada request rastreada
     * significava que TODA installation (mesmo quem nunca publicou
     * `config/monitor.php`) já vinha apagando `monitor_visits` com mais de
     * 90 dias sozinha, automaticamente. Isso nunca foi intencional — o
     * gatilho automático foi desenhado só pra IP já confirmado-bloqueado
     * (`only_blocked=true`), não pra varrer visitas de todo mundo por
     * idade (ver `maybeCleanup()`, sempre chama `prune(0, true)`, nunca
     * `false`). Com o default de `visits_retention_days` agora `0`
     * (desligado), esse cleanup vira 100% manual: sem este bloco, um
     * device ativo (cookie de remember-me de 5 anos, `pruneMonitors()`
     * nunca o apaga) acumularia `monitor_visits` pra sempre, sem NENHUM
     * jeito de limpar via `pruneData`/`monitor:prune --older-than-days`
     * (que é justamente a ferramenta manual/administrativa que existe pra
     * isso). Ligando este bloco a `$onlyBlocked = false` (nunca a `true`)
     * garante que o caminho automático (`maybeCleanup()` → sempre
     * `prune(0, true)`) continua NUNCA apagando visitas por conta própria —
     * ver `MonitorDataPruneAutoTest`/o teste de regressão equivalente no
     * harness.
     *
     * Sem sobreposição/dupla-contagem com `pruneVisits()`: são dois
     * `DELETE ... WHERE updated_at < ?` independentes (cutoffs
     * tipicamente diferentes — um por `visits_retention_days`, outro por
     * `$olderThanDays`); uma linha que a primeira já apagou simplesmente
     * não é encontrada pela segunda (delete idempotente por natureza), e
     * uma linha que sobra pra segunda encontrar é, por definição, uma que
     * a primeira não pegou.
     */
    public static function prune(int $olderThanDays, bool $onlyBlocked, ?int $maxRows = null): array
    {
        $cutoff = now()->subDays($olderThanDays);

        $monitors = self::pruneMonitors($cutoff, $onlyBlocked, $maxRows);

        $ipStatQuery = IpStat::where('last_seen', '<', $cutoff);

        if ($onlyBlocked) {
            $ipStatQuery->whereIn('ip', BlockedIp::active()->pluck('ip'));
        }

        $ipStatsDeleted = $ipStatQuery->delete();

        if ($monitors['deleted'] > 0) {
            self::invalidatePagesCache();
        }

        $visits = self::pruneVisits($maxRows);

        $cutoffVisits = $onlyBlocked
            ? ['deleted' => 0, 'done' => true]
            : self::deleteVisitsOlderThan($cutoff, $maxRows);

        $visitsDeleted = $visits['deleted'] + $cutoffVisits['deleted'];

        // laravel-monitor 242 (v0.50.0): passou a incluir
        // `monitors['deleted']` (antes só ip_stats/visits) — a nova
        // listagem `getTableStats` (MonitorController) reflete a contagem
        // de linhas de `monitors` também, e usa o MESMO cache versionado
        // via `listingsCacheKey()`, então um prune que só apaga `monitors`
        // (ex: `only_blocked=false` sem nenhum IpStat/visit elegível)
        // precisa invalidar esse cache igual. Sem custo extra pras outras
        // listagens (getVisitorsByIp/getBlockedIps/getBlockedPaths): elas
        // já são invalidadas com mais frequência do que precisam noutros
        // pontos do código, invalidar aqui também só significa um cache
        // miss a mais, nunca um dado errado.
        if ($monitors['deleted'] > 0 || $ipStatsDeleted > 0 || $visitsDeleted > 0) {
            ListingsCache::invalidate();
        }

        return [
            'monitors_deleted' => $monitors['deleted'],
            'ip_stats_deleted' => $ipStatsDeleted,
            'visits_deleted' => $visitsDeleted,
            'done' => $monitors['done'] && $visits['done'] && $cutoffVisits['done'],
        ];
    }

    /**
     * Retenção própria de `monitor_visits` (`monitor.visits_retention_days`,
     * 0 = desliga), independente da idade do Monitor pai e de
     * `$onlyBlocked`/`$olderThanDays` de `prune()`: as visitas são a parte
     * pesada do tracking, e um device ativo (cookie de remember-me de 5
     * anos) nunca é apagado por `pruneMonitors()` — sem isto as visitas
     * dele se acumulariam pra sempre. As visitas de Monitors apagados já
     * somem pelo `cascadeOnDelete` da FK.
     *
     * `$maxRows` (só o caminho automático passa) segue o mesmo esquema de
     * `pruneMonitors()`: busca no máximo `$maxRows + 1` ids (a linha extra só
     * detecta se sobrou backlog) e apaga os primeiros `$maxRows`, ordenado
     * por id — `done=false` quando sobrou. Fail-open: tabela ainda não
     * migrada não pode derrubar a request que disparou o prune.
     *
     * @return array{deleted: int, done: bool}
     */
    public static function pruneVisits(?int $maxRows = null): array
    {
        // Default `0` (desligado) desde a v0.46.0 — até 0.45.0 era `90`,
        // ver comentário de `visits_retention_days` em config/monitor.php
        // e de `prune()` acima pra por que isso mudou.
        $days = (int) config('monitor.visits_retention_days', 0);

        if ($days <= 0) {
            return ['deleted' => 0, 'done' => true];
        }

        return self::deleteVisitsOlderThan(now()->subDays($days), $maxRows);
    }

    /**
     * Núcleo compartilhado de `pruneVisits()` (cutoff via
     * `visits_retention_days`) e do bloco `only_blocked=false` de
     * `prune()` (cutoff via `$olderThanDays`) — mesmo
     * `DELETE ... WHERE updated_at < $cutoff`, chunked/capado por
     * `$maxRows` do mesmo jeito nos dois casos, só o cutoff em si muda
     * conforme o chamador.
     *
     * @return array{deleted: int, done: bool}
     */
    private static function deleteVisitsOlderThan(Carbon $cutoff, ?int $maxRows): array
    {
        try {
            $query = DB::table('monitor_visits')->where('updated_at', '<', $cutoff);

            if ($maxRows === null) {
                return ['deleted' => $query->delete(), 'done' => true];
            }

            $ids = $query->orderBy('id')->limit($maxRows + 1)->pluck('id');
            $done = $ids->count() <= $maxRows;
            $ids = $ids->take($maxRows);

            if ($ids->isEmpty()) {
                return ['deleted' => 0, 'done' => $done];
            }

            return [
                'deleted' => DB::table('monitor_visits')->whereIn('id', $ids)->delete(),
                'done' => $done,
            ];
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_visits não encontrada ao podar visitas — rode `php artisan migrate` ou `php artisan monitor:update`. Erro original: '.$e->getMessage());

            return ['deleted' => 0, 'done' => true];
        }
    }

    /**
     * Sem `only_blocked`, o delete é direto em SQL (bulk, sem carregar
     * nada em PHP) — inalcançável a partir de `maybeCleanup()` (sempre
     * chama com `only_blocked=true`), então `$maxRows` não se aplica a
     * esse ramo.
     *
     * Com `only_blocked=true` (laravel-monitor 141): antes fazia o mesmo
     * contorno de `buildPagesResult` pré-103 — abria `data.ips` (blob
     * JSON) em chunks de 200 pra achar interseção com IP bloqueado em PHP,
     * sem índice. `monitor_visit_ips` (task 104, já mantida em sincronia a
     * cada save de `Monitor`) resolve o mesmo mapeamento IP->Monitor via
     * índice em `ip`, então o join agora é uma query indexada só.
     *
     * Task 145 — teto de linhas por execução (`$maxRows`, só setado pelo
     * caminho automático): em vez de `pluck()` de todos os IDs elegíveis
     * pra PHP sem limite, busca no máximo `$maxRows + 1` (a linha extra
     * só serve pra detectar se sobrou mais sem precisar contar o total) e
     * apaga só os primeiros `$maxRows`, ordenado por `monitor_id` pra cada
     * chamada avançar num pedaço diferente do backlog em vez de reprocessar
     * sempre o mesmo topo indefinido. `done=false` quando a linha extra
     * apareceu (sobrou backlog); `maybeCleanup()` usa isso pra não gravar o
     * timestamp de "último run" e deixar a próxima request continuar dessa
     * mesma query (sem estado extra — não há lista congelada, então não há
     * risco de dado desatualizado, só custo de requery, aceitável dado que
     * o backlog em regime estacionário é ~zero, ver task 145).
     *
     * `monitor_ip_stats` (em `prune()`) NÃO usa o mesmo teto: a lista de
     * IPs bloqueados que restringe aquele delete (`BlockedIp::active()->
     * pluck('ip')`) é limitada ao tamanho de `monitor_blocked_ips` (181
     * linhas medidas em produção — cresce devagar, é a MESMA lista que já
     * paginava sem teto em `BlockedIpCleaner`), não ao volume de tracking
     * acumulado que motivou este teto; comprovadamente leve o bastante pra
     * não precisar de chunk.
     *
     * Task 147: `only_blocked` só considera bloqueio VIGENTE
     * (`BlockedIp::active()`, mesmo predicado de `MonitorMethod::
     * isBlocked()`) — antes usava `BlockedIp::pluck('ip')` sem filtro,
     * tratando um bloqueio temporário já expirado como "ainda bloqueado"
     * pra fins de purga, apagando o tracking de um IP que já voltou a ser
     * um visitante normal (a linha em `monitor_blocked_ips` continua
     * existindo de propósito depois de expirar, só pra alimentar a escada
     * de `strike_count`/`lifetime_offense_count` do próximo bloqueio, ver
     * `ScraperBlocker::registerOffense`).
     */
    public static function pruneMonitors(Carbon $cutoff, bool $onlyBlocked, ?int $maxRows = null): array
    {
        if (! $onlyBlocked) {
            return [
                'deleted' => Monitor::where('updated_at', '<', $cutoff)->delete(),
                'done' => true,
            ];
        }

        $blockedIps = BlockedIp::active()->pluck('ip');

        if ($blockedIps->isEmpty()) {
            return ['deleted' => 0, 'done' => true];
        }

        $query = DB::table('monitor_visit_ips')
            ->whereIn('ip', $blockedIps)
            ->distinct();

        if ($maxRows === null) {
            $ids = $query->pluck('monitor_id');

            if ($ids->isEmpty()) {
                return ['deleted' => 0, 'done' => true];
            }

            return [
                'deleted' => Monitor::whereIn('id', $ids)->where('updated_at', '<', $cutoff)->delete(),
                'done' => true,
            ];
        }

        $ids = $query->orderBy('monitor_id')->limit($maxRows + 1)->pluck('monitor_id');
        $done = $ids->count() <= $maxRows;
        $ids = $ids->take($maxRows);

        if ($ids->isEmpty()) {
            return ['deleted' => 0, 'done' => $done];
        }

        return [
            'deleted' => Monitor::whereIn('id', $ids)->where('updated_at', '<', $cutoff)->delete(),
            'done' => $done,
        ];
    }

    /**
     * Mesmo contador de versão usado por `getPages`
     * (`MonitorController::buildPagesResult`, chave `monitor:pages:version`)
     * — incrementar invalida todo cache de páginas em uma escrita O(1), sem
     * precisar varrer/apagar chaves individuais.
     */
    private static function invalidatePagesCache(): void
    {
        if (! Cache::has('monitor:pages:version')) {
            Cache::forever('monitor:pages:version', 1);
        }

        Cache::increment('monitor:pages:version');
    }
}
