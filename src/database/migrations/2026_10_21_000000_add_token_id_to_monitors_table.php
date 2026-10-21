<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 400: `token_id` guarda o `personal_access_tokens.id`
     * (Sanctum) do token que criou este Monitor — espelha exatamente o
     * papel de `id_token` (migration `2026_09_21_000001`, cookie de
     * remember-me do tracker web): uma coluna própria indexada em vez de
     * um `where('data->token_id', ...)` (scan sem índice na coluna JSON),
     * mesmo raciocínio documentado lá.
     *
     * Grouping é "um Monitor por TOKEN" (não por usuário — dois tokens do
     * mesmo usuário são dois Monitors, ver README/CHANGELOG da task 400),
     * então o valor aqui é só o id do token, nunca o id do usuário (esse
     * fica em `data.user_id`, já existente).
     *
     * Nullable + unique: Monitors do tracker web (session) e do fallback
     * por IP do `MonitorApiMethod` (sem token) ficam com `NULL` — todos os
     * SGBDs suportados aceitam vários `NULL` numa unique key.
     *
     * `unsignedBigInteger` sem `foreignId()`: `personal_access_tokens` é
     * uma tabela do Sanctum, NÃO deste pacote — uma FK de verdade exigiria
     * `laravel/sanctum` instalado (e migrado) SEMPRE, quebrando a
     * dependência opcional (ver README "Sanctum (optional)"). Sem
     * constraint, a validade é garantida em código
     * (`Support\ApiTokenVisitorTracker`), nunca pelo banco.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedBigInteger('token_id')->nullable()->unique()->after('id_token');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropUnique(['token_id']);
            $table->dropColumn('token_id');
        });
    }
};
