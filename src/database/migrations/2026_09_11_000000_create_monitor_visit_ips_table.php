<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por (Monitor, ip) — desnormaliza `data.ips` (blob JSON por
     * visitante) numa tabela relacional indexável por `ip`, mesmo padrão
     * de `monitor_page_hits` (laravel-monitor 103). Mantida em sincronia
     * por `Monitor::booted()` (evento `saved`, ver Models/Monitor.php) a
     * cada escrita, e populada aqui, uma única vez, a partir do `data` já
     * existente. Corrige `getVisitorPaths()`, que escaneava e decodificava
     * o JSON de TODA a tabela `Monitor` a cada chamada pra achar quais
     * linhas continham um IP (mesma classe de bug que motivou a 103 em
     * `buildPagesResult()`, ver bugs/laravel-monitor.md).
     */
    public function up(): void
    {
        Schema::create('monitor_visit_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('ip');
            $table->timestamps();

            $table->unique(['monitor_id', 'ip']);
            $table->index('ip');
        });

        DB::table('monitors')->orderBy('id')->select('id', 'data')->chunkById(200, function ($monitors) {
            $rows = [];

            foreach ($monitors as $monitor) {
                $data = json_decode((string) $monitor->data, true) ?? [];
                $ips = (array) ($data['ips'] ?? []);

                foreach (array_unique($ips) as $ip) {
                    $rows[] = [
                        'monitor_id' => $monitor->id,
                        'ip' => (string) $ip,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($rows) {
                DB::table('monitor_visit_ips')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_visit_ips');
    }
};
