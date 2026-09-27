<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Drcantagalo\LaravelMonitor\Models\MonitorAccessLog;
use Illuminate\Console\Command;

/**
 * laravel-monitor 249: a FONTE DA VERDADE do log de acessos — ao
 * contrário da action remota `getAccessLog`, que é servida pelo próprio
 * dashboard (que, em tese, poderia filtrar o que mostra), este comando
 * lê o banco local diretamente. `--purge` é a única forma manual de
 * apagar linhas (a automática é `Support\DataPruner::pruneAccessLogs()`,
 * via `access_log_retention_days`).
 */
class MonitorAccessLogCommand extends Command
{
    protected $signature = 'monitor:access-log {--days=} {--limit=50} {--purge}';

    protected $description = 'List recent monitor_access_logs entries (who/what read your data), or purge them manually';

    public function handle(): int
    {
        if ($this->option('purge')) {
            return $this->purge();
        }

        return $this->list();
    }

    private function list(): int
    {
        $days = $this->option('days');
        $limit = max(1, (int) $this->option('limit'));

        $query = MonitorAccessLog::query()->orderByDesc('accessed_at')->orderByDesc('id');

        if (is_numeric($days) && (int) $days >= 0) {
            $query->where('accessed_at', '>=', now()->subDays((int) $days));
        }

        $rows = $query->limit($limit)->get([
            'accessed_at', 'kind', 'action', 'ip', 'user_agent', 'origin', 'token_ref', 'declared_by',
        ]);

        if ($rows->isEmpty()) {
            $this->info('No access log entries found.');

            return 0;
        }

        $this->table(
            ['Accessed at', 'Kind', 'Action', 'IP', 'User agent', 'Origin', 'Token ref', 'Declared by'],
            $rows->map(fn (MonitorAccessLog $row) => [
                optional($row->accessed_at)->toDateTimeString(),
                $row->kind,
                $row->action,
                $row->ip,
                $row->user_agent,
                $row->origin,
                $row->token_ref,
                // Nunca verificado (ver README) — rotulado aqui pra não
                // dar a entender que é uma identidade confirmada.
                $row->declared_by !== null ? "{$row->declared_by} (declared)" : null,
            ])->all()
        );

        return 0;
    }

    private function purge(): int
    {
        $count = MonitorAccessLog::count();

        if ($count === 0) {
            $this->info('No access log entries to purge.');

            return 0;
        }

        if (! $this->confirm("This will permanently delete all {$count} access log entries. Continue?")) {
            $this->info('Aborted.');

            return 1;
        }

        MonitorAccessLog::query()->delete();
        $this->info("Purged {$count} access log entries.");

        return 0;
    }
}
