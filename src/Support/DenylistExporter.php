<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Illuminate\Support\Facades\File;

/**
 * Gera um arquivo de deny-list (Apache ou Nginx) a partir de
 * `monitor_blocked_ips`, consumido por `monitor:export-denylist` (chamada
 * manual) e pela auto-exportação opt-in em `MonitorController` (ver
 * `monitor.denylist_auto_export`).
 */
class DenylistExporter
{
    public const FORMATS = ['apache', 'nginx'];

    public static function build(string $format): string
    {
        $ips = BlockedIp::orderBy('ip')->pluck('ip');

        return match ($format) {
            'apache' => $ips->map(fn ($ip) => "Require not ip {$ip}")->implode("\n") . "\n",
            'nginx' => $ips->map(fn ($ip) => "deny {$ip};")->implode("\n") . "\n",
            default => throw new \InvalidArgumentException("Invalid denylist format: {$format}"),
        };
    }

    /**
     * Grava o arquivo no path configurado (`monitor.denylist_path`),
     * criando o diretório se ainda não existir. Retorna o path escrito.
     */
    public static function writeToDisk(string $format): string
    {
        $path = config('monitor.denylist_path', storage_path('app/monitor/denylist.conf'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, self::build($format));

        return $path;
    }
}
