<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coluna gerada é sintaxe MySQL (`json_unquote(json_extract(...))`)
        // — em qualquer outro driver (sqlite, pgsql) essa migration é no-op:
        // `data['user_id']` continua gravado normalmente independente do
        // driver, só a indexação dessa chave fica MySQL-only. Ver README,
        // seção "Querying by user_id", e `Monitor::scopeForUserId()` (tem
        // fallback pro `where('data->user_id', ...)` quando a coluna gerada
        // não existe).
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('monitors', function (Blueprint $table) {
            // Coluna gerada (virtual) extraindo data['user_id'] (JSON) pra
            // permitir indexar essa chave — sem duplicar o dado, sem
            // trigger, atualizada automaticamente pelo próprio MySQL a
            // cada INSERT/UPDATE de `data`. Expressão tem que bater
            // exatamente com o que `Monitor::where('data->user_id', $id)`
            // compila (confirmado via ->toRawSql()), senão o otimizador
            // não reconhece a coluna gerada como equivalente e não usa o
            // índice. Nome de coluna e de índice EXPLÍCITOS e curtos —
            // MySQL tem limite de 64 chars pro nome de identificador, e o
            // nome auto-gerado do Laravel (convenção
            // `<table>_<columns>_index`) estoura fácil esse limite com
            // nomes de coluna compostos.
            $table->string('monitors_user_id', 191)
                ->nullable()
                ->virtualAs('json_unquote(json_extract(`data`, \'$."user_id"\'))')
                ->after('data')
                ->index('monitors_user_id_idx');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex('monitors_user_id_idx');
            $table->dropColumn('monitors_user_id');
        });
    }
};
