<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 286: redesenha `monitor_page_hits` com dimensão de
     * dia (`monitor_id, path, day`), unique `(monitor_id, path, day)` —
     * decidido com o usuário em 2026-10-04 (ver
     * historico/laravel-monitor.md, entrada "redesign monitor_page_hits
     * por dia"). Resolve três problemas da tabela desenhada na 103
     * (`2026_09_10_000000_create_monitor_page_hits_table`, uma linha por
     * Monitor+path, sem dimensão de tempo): (1) `buildPagesResult`
     * (`getPages`) filtrava `date_from`/`date_to` via JOIN com
     * `monitors.updated_at` — "esse visitante esteve ativo nessa janela",
     * não "esse path foi batido nessa janela", somando hits da vida
     * inteira do visitante mesmo fora do período pedido; (2) não existia
     * série diária por path pro novo gráfico do dashboard
     * (`getPageTimeline`, só pra isso); (3) `DataPruner::pruneVisits()`
     * (`visits_retention_days`) apagava `monitor_visits` mas o perfil de
     * navegação por visitante em `monitor_page_hits` ficava pra sempre,
     * mesmo pro tracker anônimo (sem visita).
     *
     * Continua FILHA DE `monitors` (cascade), nunca de `monitor_visits`
     * de propósito: o tracker anônimo (bots/API, sem sessão) nunca cria
     * visita, `track_visits=false` idem, e uma visita tem teto
     * (`visit_max_paths`) que não existe aqui — virar filha de visita
     * faria esses hits sumirem ou ficarem incompletos.
     *
     * Migração dos dados existentes: como a unique key ATUAL (antes desta
     * migration) já é `(monitor_id, path)` — uma linha por par, sem
     * dimensão de tempo — não há o que mesclar: cada linha vira
     * `day = DATE(updated_at)` diretamente. É só uma aproximação (a
     * última atividade daquele path por aquele visitante, não todo dia em
     * que ele bateu nele) — documentada no CHANGELOG/README.
     * `monitor_settings.page_hits_exact_since` grava a data em que esta
     * migration rodou: dias a partir dessa data já são escritos com a
     * dimensão de dia de verdade (exatos); dias antes dela vêm desse
     * backfill aproximado. `getPages`/`getPageTimeline` expõem esse valor
     * como `exact_since` no payload, pro dashboard avisar o usuário.
     *
     * `day` fica NULLABLE no schema (sem `doctrine/dbal` no
     * `composer.json` — este pacote nunca usa `->change()`/`renameColumn`
     * em nenhuma migration, de propósito, pra não exigir essa dependência
     * só por causa de uma migration) — a garantia de que toda linha NOVA
     * sempre tem `day` preenchido é responsabilidade do código
     * (`Monitor::recordHit()`), não do schema.
     */
    public function up(): void
    {
        if (! Schema::hasTable('monitor_settings')) {
            Schema::create('monitor_settings', function (Blueprint $table) {
                $table->string('key', 191)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        $now = now();

        DB::table('monitor_settings')->updateOrInsert(
            ['key' => 'page_hits_exact_since'],
            ['value' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now]
        );

        if (! Schema::hasTable('monitor_page_hits')) {
            return;
        }

        if (! Schema::hasColumn('monitor_page_hits', 'day')) {
            Schema::table('monitor_page_hits', function (Blueprint $table) {
                $table->date('day')->nullable()->after('path');
            });
        }

        DB::table('monitor_page_hits')->whereNull('day')->update(['day' => DB::raw('DATE(updated_at)')]);

        // Cria os índices novos ANTES de derrubar os antigos: `monitor_id`
        // tem uma foreign key (`constrained('monitors')`) e nunca teve um
        // índice próprio só pra ela — o InnoDB sempre usou o unique
        // `(monitor_id, path)` como índice de suporte da FK (ele começa
        // com `monitor_id`). Se a ordem for invertida (dropar o unique
        // antigo antes de criar o novo), existe uma janela sem NENHUM
        // índice cobrindo `monitor_id` e o MySQL recusa o DROP com erro
        // 1553 ("needed in a foreign key constraint") — foi exatamente o
        // que aconteceu no deploy de produção (ver deploy-errors/
        // home-page.md, commit 94a5f73). O unique novo também começa com
        // `monitor_id`, então criá-lo primeiro mantém a FK sempre
        // suportada durante a troca.
        Schema::table('monitor_page_hits', function (Blueprint $table) {
            $table->unique(['monitor_id', 'path', 'day']);
            $table->index(['path', 'day']);
            $table->index('day');
        });

        Schema::table('monitor_page_hits', function (Blueprint $table) {
            $table->dropUnique(['monitor_id', 'path']);
            $table->dropIndex(['path']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('monitor_page_hits') && Schema::hasColumn('monitor_page_hits', 'day')) {
            // Mesmo cuidado do up(): recria o unique antigo (também líder
            // em `monitor_id`) antes de derrubar o novo, pra nunca deixar
            // a FK sem índice de suporte.
            Schema::table('monitor_page_hits', function (Blueprint $table) {
                $table->unique(['monitor_id', 'path']);
                $table->index('path');
            });

            Schema::table('monitor_page_hits', function (Blueprint $table) {
                $table->dropUnique(['monitor_id', 'path', 'day']);
                $table->dropIndex(['path', 'day']);
                $table->dropIndex(['day']);
            });

            Schema::table('monitor_page_hits', function (Blueprint $table) {
                $table->dropColumn('day');
            });
        }

        Schema::dropIfExists('monitor_settings');
    }
};
