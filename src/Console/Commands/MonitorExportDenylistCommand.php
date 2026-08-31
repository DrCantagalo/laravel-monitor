<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Illuminate\Console\Command;
use Drcantagalo\LaravelMonitor\Support\DenylistExporter;

class MonitorExportDenylistCommand extends Command
{
    protected $signature = 'monitor:export-denylist {--format=}';

    protected $description = 'Export monitor_blocked_ips as an Apache or Nginx deny-list snippet';

    public function handle(): int
    {
        $format = $this->option('format') ?: config('monitor.denylist_format', 'apache');

        if (! in_array($format, DenylistExporter::FORMATS, true)) {
            $this->error('Invalid --format. Use "apache" or "nginx".');

            return 1;
        }

        $path = DenylistExporter::writeToDisk($format);

        $this->info("Denylist exported to {$path} ({$format} format).");

        if ($format === 'apache') {
            $this->line('Include this file in your vhost config — Apache re-reads it automatically, no reload needed.');
        } else {
            $this->line('Run "nginx -s reload" (or your usual reload mechanism) for this file to take effect — Nginx does not pick up config changes on its own.');
        }

        return 0;
    }
}
