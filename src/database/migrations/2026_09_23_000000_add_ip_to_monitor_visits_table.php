<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 152 (v0.46.0): `ip` guarda o IP que ABRIU a visita —
     * gravado só na criação da linha em `Support\VisitRecorder::record()`
     * (uma visita pode atravessar troca de IP no meio, ver
     * `SessionVisitorTracker::recordIpIfChanged()`/`monitor_visit_ips`; esta
     * coluna não acompanha isso, é só o "quem começou"). Sem índice de
     * propósito: já existe `monitor_visit_ips.ip` (indexada) pra lookup
     * IP -> Monitor; esta coluna é só exibição em `getMonitorVisits`
     * (nova, mesma task), volume por Monitor é pequeno (paginado ali), não
     * precisa de índice próprio.
     *
     * `string(45)`: mesmo teto de tamanho usado em outras colunas de IP do
     * pacote (cobre IPv6 por extenso). Nullable: visitas já existentes
     * (gravadas antes desta migration) ficam com `NULL` — sem backfill, o
     * dado não existia em nenhum lugar pra recuperar (o IP da visita nunca
     * foi gravado por linha, só em `monitor_visit_ips`, que não guarda qual
     * IP abriu qual visita especificamente).
     */
    public function up(): void
    {
        Schema::table('monitor_visits', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_visits', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }
};
