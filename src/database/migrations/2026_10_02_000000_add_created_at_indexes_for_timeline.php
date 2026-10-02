<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 280 (v0.58.0): `getTimeline` agrupa `monitors` e
     * `monitor_visits` por dia a partir de `created_at` — sem índice
     * nessa coluna, a query varre a tabela inteira a cada chamada (sem
     * cache quente) em qualquer installation com volume real.
     * `monitor_visits` já tinha índice em `updated_at` (não serve pra
     * filtrar por `created_at`); `monitors` não tinha nenhum índice
     * simples em `created_at` até aqui.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('monitor_visits', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('monitor_visits', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
