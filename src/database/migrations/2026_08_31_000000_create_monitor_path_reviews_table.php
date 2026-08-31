<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_path_reviews', function (Blueprint $table) {
            $table->id();
            // Sempre sem host, mesmo padrão de monitor_blocked_paths - o
            // match em buildPagesResult() é feito por sufixo contra a
            // chave "host/path" de data.page, igual ao já feito ali pra
            // BlockedPath.
            $table->string('path')->unique();
            // String em vez de enum nativo do banco, pelo mesmo motivo já
            // registrado em outras tasks deste pacote (portabilidade
            // MySQL/SQLite - SQLite não tem tipo ENUM real, e o Laravel
            // simula com CHECK constraint que dificulta alterar os
            // valores aceitos no futuro). Validado na aplicação
            // (MonitorController::markPathSafe/unmarkPathSafe).
            $table->string('status', 10)->default('pending');
            // Preenchido quando status muda pra 'safe' via markPathSafe;
            // null enquanto pending (nunca revisado).
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_path_reviews');
    }
};
