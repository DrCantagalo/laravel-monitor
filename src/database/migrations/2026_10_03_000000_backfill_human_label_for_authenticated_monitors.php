<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 284 (v0.59.0): backfill pro auto-human de
     * `SessionVisitorTracker::maybeAutoHumanClassify()` — esse código novo
     * só classifica um Monitor na TRANSIÇÃO guest->autenticado (`user_id`
     * sendo gravado em `data` pela primeira vez, ver nota em
     * `SessionVisitorTracker`); Monitors que já tinham `user_id` gravado
     * ANTES desta versão nunca passam por essa transição de novo, então
     * ficariam pra sempre sem `kind` (presos na fila de triagem IA) sem
     * este backfill.
     *
     * `user_id` via coluna gerada `monitors_user_id` no MySQL (mesmo
     * fallback de `Monitor::scopeForUserId`), `data->user_id` nos demais
     * drivers (SQLite nos testes, Postgres).
     *
     * Idempotente por construção: o filtro `whereNull('ml.kind')` exclui
     * qualquer Monitor já classificado (por este backfill, manualmente, ou
     * pela IA) — rodar de novo não acha mais nada pra fazer a partir da
     * segunda vez. Nunca sobrescreve `kind` já definido (`manual`/`ai`/
     * `auth` de uma classificação anterior) — mesma regra do código em
     * runtime, nunca "um bot manual que logou continua bot".
     *
     * Dois passos (UPDATE pros que já têm linha em `monitor_labels` sem
     * `kind`, INSERT pros que não têm nenhuma) em vez de um único `upsert`
     * (`ON CONFLICT`/`ON DUPLICATE KEY`) de propósito:
     * `monitor_labels.monitor_id` deveria ter um `unique` de verdade (é
     * 1:1 com `Monitor`) mas NÃO tem, em nenhum driver — bug encontrado
     * junto com esta task (`.unique()` encadeado depois de
     * `.constrained()->cascadeOnDelete()` na migration
     * `2026_09_30_000000_move_ip_labels_to_monitor_labels_table` não cria
     * índice nenhum; ver `bugs/laravel-monitor.md` no repo claude-manager
     * pro relato completo). Sem a constraint, `upsert()` não tem um
     * conflict target válido pra usar (SQLite recusa com "ON CONFLICT
     * clause does not match any PRIMARY KEY or UNIQUE constraint";
     * MySQL/Postgres dependeriam da mesma constraint ausente) — até essa
     * constraint existir de verdade, UPDATE/INSERT separados por
     * `monitor_id` são a forma portável e correta.
     */
    public function up(): void
    {
        if (! Schema::hasTable('monitors') || ! Schema::hasTable('monitor_labels')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        $query = DB::table('monitors as m')
            ->leftJoin('monitor_labels as ml', 'ml.monitor_id', '=', 'm.id')
            ->whereNull('ml.kind');

        if ($driver === 'mysql') {
            $query->whereNotNull('m.monitors_user_id');
        } else {
            $query->whereNotNull('m.data->user_id');
        }

        $monitorIds = $query->pluck('m.id');

        if ($monitorIds->isEmpty()) {
            return;
        }

        $now = now();

        foreach ($monitorIds->chunk(500) as $chunk) {
            $idsWithLabelRow = DB::table('monitor_labels')
                ->whereIn('monitor_id', $chunk)
                ->pluck('monitor_id');

            if ($idsWithLabelRow->isNotEmpty()) {
                DB::table('monitor_labels')
                    ->whereIn('monitor_id', $idsWithLabelRow)
                    ->update([
                        'kind' => 'human',
                        'source' => 'auth',
                        'classified_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            $idsWithoutLabelRow = $chunk->diff($idsWithLabelRow);

            if ($idsWithoutLabelRow->isNotEmpty()) {
                DB::table('monitor_labels')->insert(
                    $idsWithoutLabelRow->map(fn ($id) => [
                        'monitor_id' => $id,
                        'kind' => 'human',
                        'source' => 'auth',
                        'classified_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }
        }
    }

    /**
     * Irreversível (mesma convenção já usada em outras migrations deste
     * pacote quando não há como distinguir, depois do fato, uma
     * classificação que o backfill criou de uma que já existia por outro
     * motivo) — down() não desfaz a classificação.
     */
    public function down(): void
    {
        //
    }
};
