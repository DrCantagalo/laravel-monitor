<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 295 (v0.61.0): backfill da tag reservada `user`
     * (`MonitorLabel::TAG_USER`) pra Monitors que já tinham `data['user_id']`
     * preenchido ANTES desta versão existir — `SessionVisitorTracker::
     * syncUserTag()` (chamado de `maybeAutoHumanClassify()`) só grava a tag
     * na TRANSIÇÃO guest->autenticado; um Monitor que já tinha `user_id`
     * nunca passa por essa transição de novo, então ficaria pra sempre sem
     * a tag sem este backfill. Mesmo padrão/estilo do backfill de
     * 2026-10-03 (`2026_10_03_000000_backfill_human_label_for_authenticated_monitors`,
     * laravel-monitor 284): mesma detecção de `user_id` por driver, UPDATE
     * (linha de `monitor_labels` já existe) + INSERT (não existe) separados
     * em vez de upsert, em chunks de 500.
     *
     * Idempotente por construção: tanto o UPDATE quanto o INSERT abaixo só
     * tocam Monitors cuja tag `user` ainda não está presente (checado em
     * PHP a partir do `tags` já lido) — rodar de novo não encontra mais
     * nada a fazer a partir da segunda vez. Nunca remove a tag de um
     * Monitor que a tenha e deixe de ter `user_id` (esse caminho não existe
     * hoje — ver docblock de `SessionVisitorTracker::syncUserTag()`).
     */
    public function up(): void
    {
        if (! Schema::hasTable('monitors') || ! Schema::hasTable('monitor_labels')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        $query = DB::table('monitors as m');

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
            $existingLabels = DB::table('monitor_labels')
                ->whereIn('monitor_id', $chunk)
                ->get(['monitor_id', 'tags'])
                ->keyBy('monitor_id');

            $toInsert = [];

            foreach ($chunk as $monitorId) {
                $existing = $existingLabels->get($monitorId);

                if ($existing === null) {
                    $toInsert[] = $monitorId;

                    continue;
                }

                $tags = json_decode($existing->tags ?? '', true);
                $tags = is_array($tags) ? $tags : [];

                if (in_array('user', $tags, true)) {
                    continue;
                }

                $tags[] = 'user';

                DB::table('monitor_labels')
                    ->where('monitor_id', $monitorId)
                    ->update([
                        'tags' => json_encode(array_values($tags)),
                        'updated_at' => $now,
                    ]);
            }

            if (! empty($toInsert)) {
                DB::table('monitor_labels')->insert(
                    collect($toInsert)->map(fn ($id) => [
                        'monitor_id' => $id,
                        'tags' => json_encode(['user']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }
        }
    }

    /**
     * Irreversível (mesma convenção do backfill de 2026-10-03): `down()`
     * não desfaz a tag — não há como distinguir, depois do fato, uma tag
     * `user` que este backfill criou de uma que já existisse por outro
     * motivo (hoje impossível já que a tag é reservada, mas a convenção do
     * pacote é a mesma de qualquer forma).
     */
    public function down(): void
    {
        //
    }
};
