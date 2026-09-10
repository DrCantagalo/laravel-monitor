<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por (Monitor, path) — desnormaliza `data.page`/
     * `data.not_found` (blob JSON por visitante) numa tabela relacional
     * agregável em SQL. Mantida em sincronia por `Monitor::booted()`
     * (evento `saved`, ver Models/Monitor.php) a cada escrita, e
     * populada aqui, uma única vez, a partir do `data` já existente —
     * mesmo custo de decodificar o JSON de toda a tabela `Monitor` que
     * `MonitorController::buildPagesResult()` pagava TODA REQUEST (85s
     * medidos em produção pra 35.225 linhas, ver bugs/laravel-monitor.md
     * / laravel-monitor 103), só que pago uma vez aqui em vez de a cada
     * `getPages`.
     */
    public function up(): void
    {
        Schema::create('monitor_page_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('hits')->default(0);
            $table->boolean('not_found')->default(false);
            $table->timestamps();

            $table->unique(['monitor_id', 'path']);
            $table->index('path');
        });

        // Índice em `updated_at`: buildPagesResult() passa a filtrar
        // date_from/date_to via JOIN com `monitors.updated_at` (mesma
        // semântica de sempre, só migrada de PHP pra SQL) — sem índice
        // aqui esse filtro faria table scan igual ao que estamos
        // corrigindo.
        Schema::table('monitors', function (Blueprint $table) {
            $table->index('updated_at');
        });

        DB::table('monitors')->orderBy('id')->select('id', 'data')->chunkById(200, function ($monitors) {
            $rows = [];

            foreach ($monitors as $monitor) {
                $data = json_decode((string) $monitor->data, true) ?? [];
                $pages = (array) ($data['page'] ?? []);
                $notFound = (array) ($data['not_found'] ?? []);

                foreach ($pages as $path => $hits) {
                    $rows[] = [
                        'monitor_id' => $monitor->id,
                        'path' => (string) $path,
                        'hits' => (int) $hits,
                        'not_found' => ! empty($notFound[$path]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($rows) {
                DB::table('monitor_page_hits')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex(['updated_at']);
        });

        Schema::dropIfExists('monitor_page_hits');
    }
};
