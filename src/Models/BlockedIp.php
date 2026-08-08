<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    protected $table = 'monitor_blocked_ips';

    protected $fillable = ['ip', 'source'];
}
