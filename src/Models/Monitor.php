<?php

namespace Drcantagalo\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Monitor extends Model
{
    protected $casts = [
        'data' => AsArrayObject::class,
    ];

    protected $fillable = ['data'];

    /**
     * Mantém `monitor_page_hits` (uma linha por path por Monitor, ver
     * migration `create_monitor_page_hits_table`) em sincronia com
     * `data.page`/`data.not_found` a cada save — via evento de model, não
     * chamada explícita nos trackers (diferente de `IpStat::recordVisit`),
     * porque vários testes (e o próprio `tinker`) criam/alteram `Monitor`
     * direto (`Monitor::create(['data' => [...]])`), sem passar pelos
     * trackers — só um hook no model cobre esses casos automaticamente.
     * `MonitorController::buildPagesResult()` (laravel-monitor 103) lê
     * desta tabela via SQL em vez de decodificar o JSON de toda a tabela
     * `Monitor` a cada `getPages`.
     */
    protected static function booted(): void
    {
        static::saved(function (self $monitor) {
            $monitor->syncPageHits();
            $monitor->syncVisitIps();
        });

        static::deleted(function (self $monitor) {
            DB::table('monitor_page_hits')->where('monitor_id', $monitor->id)->delete();
            DB::table('monitor_visit_ips')->where('monitor_id', $monitor->id)->delete();
        });
    }

    /**
     * Upsert em lote (uma query, `ON DUPLICATE KEY UPDATE`/`ON CONFLICT`
     * conforme o driver — mesmo padrão de `IpStat::recordVisit`/
     * `MonitorMethod::recordBlockedAttempt`) sincronizando TODO o
     * `data.page`/`data.not_found` atual deste Monitor: cada linha grava o
     * valor absoluto corrente (não um incremento), então é idempotente e
     * correto não importa como `data` foi mutado antes do save. Sem
     * delete prévio: paths só são adicionados a `data.page`, nunca
     * removidos (ver trackers), então não há linha órfã a limpar aqui.
     * Fail-open (try/catch QueryException, mesmo padrão de
     * `isPathBlocked`/`recordBlockedAttempt`): se a migration ainda não
     * rodou, não pode derrubar o site hospedeiro — o `Monitor` em si já
     * foi salvo com sucesso antes deste evento disparar, só o índice
     * derivado fica desatualizado até a migration rodar (ela faz backfill
     * do estado já existente).
     */
    protected function syncPageHits(): void
    {
        $pages = (array) data_get($this->data, 'page', []);

        if (empty($pages)) {
            return;
        }

        $notFound = (array) data_get($this->data, 'not_found', []);
        $now = now();

        $rows = [];

        foreach ($pages as $path => $hits) {
            $rows[] = [
                'monitor_id' => $this->id,
                'path' => (string) $path,
                'hits' => (int) $hits,
                'not_found' => ! empty($notFound[$path]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            DB::table('monitor_page_hits')->upsert(
                $rows,
                ['monitor_id', 'path'],
                ['hits', 'not_found', 'updated_at']
            );
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_page_hits não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
        }
    }

    /**
     * Mesmo padrão de `syncPageHits()`, só que pra `data.ips` em vez de
     * `data.page` — mantém `monitor_visit_ips` (uma linha por IP por
     * Monitor, ver migration `create_monitor_visit_ips_table`) em
     * sincronia, pra `MonitorController::getVisitorPaths()` (laravel-monitor
     * 104) achar por índice quais Monitor viram um IP, em vez de escanear
     * `data` de toda a tabela. `insertOrIgnore` em vez de `upsert`: ao
     * contrário de `data.page` (hits pode mudar de valor pro mesmo path),
     * um par (monitor_id, ip) não tem coluna própria pra atualizar — só
     * existe ou não existe, então não faz sentido "atualizar", só evitar
     * duplicata (a unique key já garante isso, `insertOrIgnore` só evita o
     * erro de constraint quando o par já foi inserido num save anterior).
     */
    protected function syncVisitIps(): void
    {
        $ips = array_unique((array) data_get($this->data, 'ips', []));

        if (empty($ips)) {
            return;
        }

        $now = now();

        $rows = array_map(fn ($ip) => [
            'monitor_id' => $this->id,
            'ip' => (string) $ip,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ips);

        try {
            DB::table('monitor_visit_ips')->insertOrIgnore($rows);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_visit_ips não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
        }
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
