<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only: nenhuma action remota do pacote edita ou apaga linhas
 * daqui (nem `clearData`, nem `pruneData`/`DataPruner::prune()`) — só
 * `Support\DataPruner::pruneAccessLogs()` (retenção automática local) e
 * `monitor:access-log --purge` (manual) apagam. Ver laravel-monitor 249.
 */
class MonitorAccessLog extends Model
{
    protected $table = 'monitor_access_logs';

    protected $fillable = ['accessed_at', 'kind', 'action', 'ip', 'user_agent', 'origin', 'token_ref', 'declared_by'];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];
}
