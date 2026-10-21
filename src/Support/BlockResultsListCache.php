<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Illuminate\Support\Facades\Cache;

/**
 * laravel-monitor 402: versao da chave de cache de `getBlockResults`
 * (listagem paginada de `monitor_block_results`, `MonitorController`) e
 * de `getApiBlockEvents` (laravel-monitor 400, `monitor_api_block_events`)
 * - MESMO padrao de `ListingsCache`, mas um contador PROPRIO, separado.
 *
 * Por que nao reusar `ListingsCache::VERSION_KEY` direto: aquele esquema
 * assume mutacao RARA (acao manual de admin bloqueando/desbloqueando
 * algo - ver docblock de `ListingsCache`); `monitor_block_results`/
 * `monitor_api_block_events` incrementam a cada request bloqueada
 * (potencialmente centenas por segundo sob ataque), e as duas listagens
 * ja sao cacheadas com TTL curto e FIXO (`block_results_cache_ttl_seconds`),
 * de proposito fora do esquema versionado - ver comentario em
 * `MonitorController::blockedAttemptsTotal()`. Esta classe existe so pro
 * novo handler `clearCache` (task 402) ter como invalidar
 * `monitor:block-results:list:*`/`monitor:api-block-events:list:*` sem
 * precisar prever/escanear cada chave individual (a chave inclui um hash
 * de `[page, per_page]` - variavel, nao da pra `Cache::forget()` direto,
 * e a maioria dos cache stores (file/array) nao suporta wildcard/
 * tag-based delete barato/portavel). Nunca invalidado pelo trafego
 * normal (so pelo `clearCache` explicito) - diferente de `ListingsCache`,
 * que E invalidado a cada acao de escrita.
 */
class BlockResultsListCache
{
    public const VERSION_KEY = 'monitor:block-results:list-version';

    /**
     * `$prefix` distingue os dois consumidores (`block-results`/
     * `api-block-events`) dentro da MESMA versao - sem isso as duas
     * listagens colidiriam de chave sempre que `[page, per_page]` fosse
     * igual (ex: ambas pedindo pagina 1 com 20 por pagina).
     */
    public static function key(string $prefix, array $params): string
    {
        $version = Cache::get(self::VERSION_KEY, 1);

        return "monitor:{$prefix}:list:v{$version}:".md5(json_encode($params));
    }

    public static function invalidate(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);
        }

        Cache::increment(self::VERSION_KEY);
    }
}
