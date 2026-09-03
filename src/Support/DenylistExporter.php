<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Illuminate\Support\Facades\File;

/**
 * Gera um arquivo de deny-list (Apache ou Nginx) a partir de
 * `monitor_blocked_ips`, consumido por `monitor:export-denylist` (comando
 * artisan) e pela action `exportDenylist` da API do `MonitorController`
 * (ambos disparados explicitamente pelo consumidor do pacote - o pacote
 * não exporta sozinho).
 */
class DenylistExporter
{
    public const FORMATS = ['apache', 'nginx'];

    /**
     * Exclui IPs com `blocked_until` já expirado (mesmo filtro de
     * `MonitorMethod::isBlocked()`), **mais** IPs cujo `blocked_until`
     * ainda não expirou mas expira antes do próximo export previsto
     * (`config('monitor.denylist_export_interval_hours')`) — o consumidor
     * deve setar esse valor batendo com a frequência real do cron que ele
     * mesmo configura pra rodar `monitor:export-denylist`/`exportDenylist`
     * (ver README).
     *
     * Motivo, é sutil: um bloqueio que vai expirar antes do próximo export
     * nunca teria seu `Require not ip`/`deny` removido a tempo, deixando o
     * Apache/Nginx bloqueando um IP que o Laravel já liberou. Exigir que o
     * tempo restante seja >= o intervalo de export garante matematicamente
     * que isso nunca acontece: se resta >= intervalo, o bloqueio no
     * Laravel ainda é válido até pelo menos o próximo export recalcular o
     * arquivo. `blocked_until = null` (permanente) sempre entra, sem essa
     * checagem.
     */
    public static function build(string $format): string
    {
        $exportThreshold = now()->addHours((int) config('monitor.denylist_export_interval_hours', 24));

        $ips = BlockedIp::where(
            fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '>=', $exportThreshold)
        )->orderBy('ip')->pluck('ip');

        return match ($format) {
            'apache' => $ips->map(fn ($ip) => "Require not ip {$ip}")->implode("\n")."\n",
            'nginx' => $ips->map(fn ($ip) => "deny {$ip};")->implode("\n")."\n",
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
