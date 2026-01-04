<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class Monitor extends Model
{
    protected $casts = [
        'data' => AsArrayObject::class,
    ];

    protected $fillable = ['data'];

    public function newVisit($session_id, $ip)
    {
        $sessions_array = $this->data['sessions'] ?? [];
        if (!in_array($session_id, $sessions_array)) {
            $this->data['sessions'][] = $session_id;
        }

        $ips_array = $this->data['ips'] ?? [];
        if (!in_array($ip, $ips_array)) {
            $this->data['ips'][] = $ip;
        }

        $this->data['visits'] = ($this->data['visits'] ?? 0) + 1;

        $this->save();
    }
}
