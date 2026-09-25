<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_ip_labels', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            // null = indefinido (todo IP começa assim, sem linha nesta
            // tabela). 'bot'/'human' validados na aplicação, não via
            // enum() do banco (SQLite não suporta enum, e trocar os
            // valores aceitos no futuro não pode exigir uma migration).
            $table->string('kind')->nullable();
            $table->json('tags')->nullable();
            $table->text('note')->nullable();
            // 'manual' (default) | 'ai' (fase 3, task home-page 241).
            $table->string('source')->default('manual');
            $table->dateTime('classified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_ip_labels');
    }
};
