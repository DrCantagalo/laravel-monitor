<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 136: elimina o flag `safe` de IP (`monitor_ip_stats.safe`,
 * adicionado em `2026_08_31_000001_add_safe_to_monitor_ip_stats_table`) —
 * IP não é uma identidade estável o bastante (pool dinâmico, CGNAT, IP
 * reciclado) pra justificar uma whitelist permanente baseada só nele.
 * `safe` de PATH (`monitor_paths`) não muda — é uma identidade estável,
 * diferente de IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_ip_stats', function (Blueprint $table) {
            $table->dropColumn('safe');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_ip_stats', function (Blueprint $table) {
            $table->boolean('safe')->default(false)->after('flagged_signals');
        });
    }
};
