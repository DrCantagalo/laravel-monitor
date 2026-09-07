<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sempre sem host, mesmo padrão das duas tabelas que esta migration
        // funde (ver comentários nas migrations antigas). String em vez de
        // enum nativo do banco, mesmo motivo já registrado nelas
        // (portabilidade MySQL/SQLite). Validado na aplicação
        // (MonitorController) - só 'safe'|'trap' são gravados; ausência de
        // linha continua significando 'pending', não precisa de linha pra
        // representar esse estado.
        Schema::create('monitor_paths', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('status', 10);
            // Preenchido quando status vira 'safe' via markPathSafe(s);
            // null pra linhas 'trap' (flagScraperPath nunca setou isso).
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // monitor_blocked_paths (presença de linha = bloqueado) e
        // monitor_path_reviews (linha com status='safe' = revisado seguro)
        // eram tabelas independentes, sem FK entre si e sem nenhuma ação
        // limpando a tabela irmã - um path podia ficar marcado como trap E
        // safe ao mesmo tempo, estado logicamente contraditório (visto na
        // prática em produção, cantagalo.it: um path honeypot que também
        // tinha sido marcado seguro por engano). monitor_blocked_paths já
        // causa bloqueio ativo (403) hoje independente do que
        // monitor_path_reviews diga (MonitorMethod::isPathBlocked só olhava
        // BlockedPath), então em caso de conflito 'trap' vence - 'safe'
        // vencer desbloquearia silenciosamente paths hoje ativamente
        // bloqueados, uma regressão de segurança.
        $blockedPaths = DB::table('monitor_blocked_paths')->get();
        $safeReviews = DB::table('monitor_path_reviews')->where('status', 'safe')->get();
        $blockedPathNames = $blockedPaths->pluck('path')->flip();

        foreach ($blockedPaths as $blockedPath) {
            if ($safeReviews->contains('path', $blockedPath->path)) {
                Log::warning("[laravel-monitor] path '{$blockedPath->path}' estava marcado como bloqueado (monitor_blocked_paths) E como seguro (monitor_path_reviews) simultaneamente - resolvido para 'trap' na fusão em monitor_paths (mantém o comportamento de proteção já em vigor).");
            }

            DB::table('monitor_paths')->insert([
                'path' => $blockedPath->path,
                'status' => 'trap',
                'reviewed_at' => null,
                'created_at' => $blockedPath->created_at,
                'updated_at' => $blockedPath->updated_at,
            ]);
        }

        foreach ($safeReviews as $safeReview) {
            if ($blockedPathNames->has($safeReview->path)) {
                // Já inserido como 'trap' acima - conflito já logado, 'trap'
                // vence, o registro 'safe' correspondente é descartado.
                continue;
            }

            DB::table('monitor_paths')->insert([
                'path' => $safeReview->path,
                'status' => 'safe',
                'reviewed_at' => $safeReview->reviewed_at,
                'created_at' => $safeReview->created_at,
                'updated_at' => $safeReview->updated_at,
            ]);
        }

        Schema::dropIfExists('monitor_blocked_paths');
        Schema::dropIfExists('monitor_path_reviews');
    }

    public function down(): void
    {
        Schema::create('monitor_blocked_paths', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->timestamps();
        });

        Schema::create('monitor_path_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('status', 10)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Best-effort: um conflito resolvido para 'trap' no up() descartou a
        // linha 'safe' correspondente - essa informação não sobrevive pra
        // ser restaurada aqui, é intrínseco à resolução do conflito, não uma
        // limitação deste down().
        DB::table('monitor_paths')->orderBy('id')->chunk(200, function ($paths) {
            foreach ($paths as $path) {
                if ($path->status === 'trap') {
                    DB::table('monitor_blocked_paths')->insert([
                        'path' => $path->path,
                        'created_at' => $path->created_at,
                        'updated_at' => $path->updated_at,
                    ]);
                } else {
                    DB::table('monitor_path_reviews')->insert([
                        'path' => $path->path,
                        'status' => $path->status,
                        'reviewed_at' => $path->reviewed_at,
                        'created_at' => $path->created_at,
                        'updated_at' => $path->updated_at,
                    ]);
                }
            }
        });

        Schema::dropIfExists('monitor_paths');
    }
};
