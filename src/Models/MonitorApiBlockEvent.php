<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * laravel-monitor 400: append-only (ver docblock da migration) — registra
 * "request bloqueada com token válido" (`MonitorApiMethod`), a única
 * origem destas linhas.
 */
class MonitorApiBlockEvent extends Model
{
    protected $table = 'monitor_api_block_events';

    protected $fillable = ['ip', 'token_id', 'token_name', 'user_id', 'message'];
}
