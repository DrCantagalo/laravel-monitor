<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class BlockResult extends Model
{
    protected $table = 'monitor_block_results';

    protected $fillable = ['ip', 'counter', 'last_attempt_at'];

    protected $casts = [
        'counter' => 'integer',
        'last_attempt_at' => 'datetime',
    ];
}
