<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    protected $table = 'monitor_blocked_ips';

    protected $fillable = ['ip', 'source', 'blocked_until', 'strike_count', 'lifetime_offense_count', 'last_offense_at'];

    protected $casts = [
        'blocked_until' => 'datetime',
        'last_offense_at' => 'datetime',
    ];

    /**
     * IP com bloqueio vigente agora: permanente (`blocked_until` nulo) ou
     * temporário ainda não expirado. Mesmo predicado de
     * `MonitorMethod::isBlocked()` (task 147) — linha ÚNICA compartilhada
     * pelos dois lugares que precisam responder "este IP está bloqueado
     * agora?", pra nunca divergir. `DataPruner` usa esta scope (em vez de
     * `BlockedIp::pluck('ip')` puro) pra restringir `only_blocked` a
     * bloqueios vigentes — uma linha com bloqueio temporário já expirado
     * continua existindo em `monitor_blocked_ips` de propósito (guarda
     * `strike_count`/`lifetime_offense_count` pra escada de
     * escalonamento), mas o IP em si voltou a ser um visitante normal.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '>', now())
        );
    }

    /**
     * Mesmo predicado de `scopeActive()`, pra uma linha já carregada: o
     * bloqueio desta linha está vigente agora (permanente, ou temporário
     * ainda não expirado)?
     */
    public function isActive(): bool
    {
        return $this->blocked_until === null || $this->blocked_until->isFuture();
    }
}
