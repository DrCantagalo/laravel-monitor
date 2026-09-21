<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorVisit extends Model
{
    protected $table = 'monitor_visits';

    protected $fillable = ['monitor_id', 'paths', 'scraper'];

    protected $casts = [
        'paths' => 'array',
        'scraper' => 'boolean',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
