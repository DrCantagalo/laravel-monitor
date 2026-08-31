<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_ip_stats', function (Blueprint $table) {
            // Mesmo padrão de monitor_path_reviews.status, mas aqui como
            // boolean numa coluna existente (não uma tabela separada):
            // monitor_ip_stats já é 1 linha por IP, então "safe" é só mais
            // um atributo da linha, não um novo estado que precise de
            // tabela própria. Default false - IP não revisado continua
            // exposto normalmente pela fila de flagged em getVisitorsByIp.
            $table->boolean('safe')->default(false)->after('flagged_signals');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_ip_stats', function (Blueprint $table) {
            $table->dropColumn('safe');
        });
    }
};
