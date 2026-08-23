<?php

namespace Drcantagalo\LaravelMonitor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void skipTracking()
 * @method static bool tag(array $data)
 * @method static bool recognize()
 *
 * @see \Drcantagalo\LaravelMonitor\Support\Monitor
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'monitor';
    }
}
