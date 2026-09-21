<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Monitor extends Model
{
    protected $casts = [
        'data' => AsArrayObject::class,
    ];

    protected $fillable = ['data', 'id_token'];

    /**
     * `monitor_page_hits` (uma linha por path por Monitor) e
     * `monitor_visit_ips` (uma linha por IP por Monitor) são a fonte da
     * verdade desses dados — gravadas direto pelos trackers via
     * `recordHit()`/`recordIp()`, não mais copiadas do blob `data` por um
     * hook `saved` (até 0.41.0, `data.page`/`data.not_found`/`data.ips`
     * eram a fonte e essas tabelas só uma cópia, com upsert de TODOS os
     * paths a cada save). Quem limpa as linhas filhas de um Monitor
     * apagado é o `cascadeOnDelete` da FK — `Monitor::where(...)->delete()`
     * em massa (DataPruner) nunca disparou eventos de model de qualquer
     * forma.
     *
     * Incremento atômico (`hits = hits + 1` no banco, mesmo padrão de
     * `IpStat::recordVisit`), sem ler-modificar-gravar: duas requests
     * simultâneas do mesmo visitante não perdem contagem. `not_found` só
     * entra na lista de update quando `$notFound` é true — "gruda em
     * true": uma vez marcado 404, nunca volta a false (mesma semântica de
     * `data.not_found[$path] = true` de antes), sem precisar de uma
     * expressão SQL específica de driver (`OR`/`GREATEST`).
     * Fail-open (try/catch QueryException, mesmo padrão de
     * `isPathBlocked`/`recordBlockedAttempt`): se a migration ainda não
     * rodou, não pode derrubar o site hospedeiro.
     */
    public function recordHit(string $path, bool $notFound = false): void
    {
        $now = now();

        $update = [
            'hits' => DB::raw('hits + 1'),
            'updated_at' => $now,
        ];

        if ($notFound) {
            $update['not_found'] = true;
        }

        try {
            DB::table('monitor_page_hits')->upsert(
                [[
                    'monitor_id' => $this->id,
                    'path' => $path,
                    'hits' => 1,
                    'not_found' => $notFound,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['monitor_id', 'path'],
                $update
            );
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_page_hits não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
        }
    }

    /**
     * Registra que este Monitor foi visto por `$ip`. `insertOrIgnore`, não
     * `upsert`: um par (monitor_id, ip) não tem coluna própria pra
     * atualizar — só existe ou não existe, e a unique key já garante que
     * não duplica. Mesmo fail-open de `recordHit()`.
     */
    public function recordIp(string $ip): void
    {
        $now = now();

        try {
            DB::table('monitor_visit_ips')->insertOrIgnore([[
                'monitor_id' => $this->id,
                'ip' => $ip,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_visit_ips não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
        }
    }

    /**
     * Mantido por compatibilidade (método público desde antes de 0.42.0):
     * `data.sessions`/`data.visits` deixaram de existir — visita agora é
     * uma linha de `monitor_visits`, criada por `Support\VisitRecorder`
     * quando a sessão não tem `monitor_visit_id` — então só registra o IP.
     */
    public function newVisit($session_id, $ip)
    {
        $this->recordIp((string) $ip);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MonitorVisit::class);
    }

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
     *
     * A coluna gerada só existe em MySQL (migration é no-op nos demais
     * drivers — ver `2026_08_20_000000_add_user_id_index_to_monitors_table`).
     * Fora do MySQL, cai pro `where('data->user_id', ...)` original: sem
     * índice (full scan), mas correto — a alternativa seria o scope quebrar
     * com "column not found" em sqlite/pgsql. **Sem** cast pra string nesse
     * fallback (diferente do caminho MySQL acima): o SQLite compara o valor
     * extraído do JSON pelo tipo de storage nativo (`json_extract` devolve
     * um INTEGER pra `{"user_id": 42}`), e `'42'` (TEXT) nunca é igual a
     * `42` (INTEGER) em SQLite — confirmado testando contra SQLite real: com
     * cast pra string o scope silenciosamente não retornava nada.
     * `user_id` sempre vem de `Auth::id()` (int), então passar o valor como
     * veio funciona nos dois drivers (confirmado também contra PostgreSQL
     * real, que aceita tanto int quanto string na comparação `->>'user_id'`).
     */
    public function scopeForUserId($query, $userId)
    {
        if ($query->getConnection()->getDriverName() !== 'mysql') {
            return $query->where('data->user_id', $userId);
        }

        return $query->where('monitors_user_id', (string) $userId);
    }
}
