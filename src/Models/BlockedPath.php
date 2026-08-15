<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedPath extends Model
{
    protected $table = 'monitor_blocked_paths';

    protected $fillable = ['path'];
}
