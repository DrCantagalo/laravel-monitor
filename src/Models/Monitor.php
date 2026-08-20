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

    /**
     * Filtra por user_id autenticado usando a coluna gerada indexada
     * (`monitors_user_id`), não a expressão JSON crua. `where('data->user_id',
     * $id)` NÃO usa o índice `monitors_user_id_idx` mesmo com a coluna gerada
     * presente (confirmado via EXPLAIN em MySQL 8 real — o otimizador não
     * casa a expressão JSON_EXTRACT/JSON_UNQUOTE da query com a definição da
     * coluna gerada nesse caso) — ver README, seção "Querying by user_id".
     * Cast pra string obrigatório: `monitors_user_id` é VARCHAR, e comparar
     * contra um int nativo via PDO faz o MySQL descartar o índice
     * (`possible_keys` lista, `key` fica NULL) por causa da conversão de
     * tipo implícita — também confirmado via EXPLAIN.
     */
    public function scopeForUserId($query, $userId)
    {
        return $query->where('monitors_user_id', (string) $userId);
    }

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
