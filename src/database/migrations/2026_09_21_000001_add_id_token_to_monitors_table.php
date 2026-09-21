<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `id_token` (o valor do cookie de remember-me) sai do blob JSON
     * (`data['id-token']`) para uma coluna própria com índice único.
     * Reconhecer um visitante que volta (a primeira request de toda sessão
     * nova com o cookie, `SessionVisitorTracker`, e
     * `Support\Monitor::recognize()`) era
     * `where('data->id-token', $token)` — `JSON_UNQUOTE(JSON_EXTRACT(...))`
     * sem índice nessa expressão, ou seja, varredura da tabela `monitors`
     * inteira decodificando o JSON de cada linha, custo que cresce com o
     * número de dispositivos. Uma coluna real funciona igual em
     * MySQL/SQLite/Postgres (ao contrário da coluna gerada MySQL-only de
     * `user_id`, ver `2026_08_20_000000_add_user_id_index_to_monitors_table`).
     *
     * Nullable + unique: linhas do tracker anônimo (sem cookie) ficam com
     * NULL, e todos os SGBDs suportados aceitam vários NULL numa unique key.
     *
     * Backfill único a partir do blob, em lotes, pra visitantes já
     * reconhecidos continuarem reconhecidos após o upgrade.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('id_token', 64)->nullable()->unique();
        });

        // Um token duplicado no blob (não deveria existir) não pode derrubar
        // a migration na unique key: o primeiro vence, o resto fica NULL.
        // Dedupe em memória (só os tokens, 40 bytes cada) — um
        // `UPDATE ... WHERE NOT EXISTS (SELECT ... FROM monitors)` daria o
        // erro 1093 do MySQL (subquery na própria tabela do UPDATE).
        $seen = [];

        DB::table('monitors')->orderBy('id')->select('id', 'data')->chunkById(200, function ($monitors) use (&$seen) {
            foreach ($monitors as $monitor) {
                $data = json_decode((string) $monitor->data, true) ?? [];
                $token = $data['id-token'] ?? null;

                if (is_string($token) && $token !== '' && ! isset($seen[$token])) {
                    $seen[$token] = true;

                    DB::table('monitors')->where('id', $monitor->id)->update(['id_token' => $token]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropUnique(['id_token']);
            $table->dropColumn('id_token');
        });
    }
};
