<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor (v0.59.1, fora da fila): `monitor_labels.monitor_id`
     * nunca teve o `unique` que o modelo 1:1 (`Monitor::label(): HasOne`)
     * pressupõe — o `->unique()` da migration
     * `2026_09_30_000000_move_ip_labels_to_monitor_labels_table` estava
     * encadeado DEPOIS de `->constrained()`, ou seja, no
     * `ForeignKeyDefinition`, onde nenhum grammar lê o atributo: nenhum
     * índice era criado em driver nenhum (ver `bugs/laravel-monitor.md`
     * no claude-manager). Essa chamada foi removida da migration original;
     * o índice é criado só aqui, pra instalações novas e existentes igual.
     *
     * Antes do índice, funde duplicatas já existentes por `monitor_id`
     * com a mesma regra da migration de 2026-09-30: `source=manual` vence
     * qualquer outra origem; dentro da mesma categoria, o `classified_at`
     * mais recente vence (null perde); empate, a linha de maior `id`.
     * Produção (cantagalo.it) não tinha nenhuma duplicata em 2026-10-04 —
     * o merge é rede de segurança pra outras instalações.
     */
    public function up(): void
    {
        if (! Schema::hasTable('monitor_labels')) {
            return;
        }

        $duplicateIds = DB::table('monitor_labels')
            ->select('monitor_id')
            ->groupBy('monitor_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('monitor_id');

        foreach ($duplicateIds as $monitorId) {
            $rows = DB::table('monitor_labels')->where('monitor_id', $monitorId)->get();

            $keep = $rows->sort(function ($a, $b) {
                $aManual = $a->source === 'manual';
                $bManual = $b->source === 'manual';

                if ($aManual !== $bManual) {
                    return $aManual ? -1 : 1;
                }

                if ($a->classified_at != $b->classified_at) {
                    if ($a->classified_at === null) {
                        return 1;
                    }

                    if ($b->classified_at === null) {
                        return -1;
                    }

                    return $a->classified_at > $b->classified_at ? -1 : 1;
                }

                return $b->id <=> $a->id;
            })->first();

            DB::table('monitor_labels')
                ->where('monitor_id', $monitorId)
                ->where('id', '!=', $keep->id)
                ->delete();
        }

        Schema::table('monitor_labels', function (Blueprint $table) {
            $table->unique('monitor_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitor_labels')) {
            return;
        }

        Schema::table('monitor_labels', function (Blueprint $table) {
            $table->dropUnique(['monitor_id']);
        });
    }
};
