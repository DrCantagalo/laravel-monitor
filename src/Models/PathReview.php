<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class PathReview extends Model
{
    protected $table = 'monitor_path_reviews';

    protected $fillable = ['path', 'status', 'reviewed_at'];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];
}
