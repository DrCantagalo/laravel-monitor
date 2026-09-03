<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    protected $table = 'monitor_blocked_ips';

    protected $fillable = ['ip', 'source', 'blocked_until', 'strike_count', 'last_offense_at'];

    protected $casts = [
        'blocked_until' => 'datetime',
        'last_offense_at' => 'datetime',
    ];
}
