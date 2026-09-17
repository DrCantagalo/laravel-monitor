<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Chave/invalidação do cache versionado usado pelas listagens do
 * dashboard (getVisitorsByIp/getBlockedIps/getBlockedPaths, em
 * MonitorController) - extraído de MonitorController::listingsCacheKey()/
 * invalidateListingsCache() (task 133) porque Support/ScraperBlocker
 * também precisa invalidar essas listagens ao bloquear um IP, e os
 * métodos originais eram protected no controller.
 */
class ListingsCache
{
    public const VERSION_KEY = 'monitor:listings:version';

    public static function key(string $prefix, array $params): string
    {
        $version = Cache::get(self::VERSION_KEY, 1);

        return "monitor:listings:{$prefix}:v{$version}:".md5(json_encode($params));
    }

    public static function invalidate(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);
        }

        Cache::increment(self::VERSION_KEY);
    }
}
