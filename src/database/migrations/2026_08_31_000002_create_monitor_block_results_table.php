<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_block_results', function (Blueprint $table) {
            $table->id();
            // Uma linha por IP (upsert em MonitorMethod::recordBlockedAttempt,
            // task 83) - unique pra permitir upsert(['ip'], ...) direto pela
            // query builder (ver README/CHANGELOG).
            $table->string('ip')->unique();
            $table->unsignedInteger('counter')->default(0);
            // Preenchido a cada upsert (não useCurrent(): precisa ser
            // atualizado manualmente a cada tentativa, não só na criação da
            // linha).
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_block_results');
    }
};
