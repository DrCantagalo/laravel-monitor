<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class MonitorPath extends Model
{
    protected $table = 'monitor_paths';

    protected $fillable = ['path', 'status', 'reviewed_at'];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];
}
