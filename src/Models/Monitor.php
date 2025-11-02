<?php

namespace Monitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class Monitor extends Model
{
    protected $casts = [
        'data' => AsArrayObject::class,
    ];

    protected $fillable = ['data'];

    // valores iniciais ao criar novo registro
    protected $attributes = [
        'data' => [
            'visits' => 0,
            'sessions' => [],
        ],
    ];

    public function newVisit($session_id)
    {
        $sessions_array = $this->data['sessions'] ?? [];
        if (!in_array($session_id, $sessions_array)) {
            $this->data['sessions'][] = $session_id;
        }

        $this->data['visits'] = ($this->data['visits'] ?? 0) + 1;

        $this->save();
    }
}
