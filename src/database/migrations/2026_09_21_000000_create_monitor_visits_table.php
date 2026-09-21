<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por visita (= uma sessão PHP) de um Monitor: `paths` guarda
     * os paths acessados em ORDEM de acesso, crus (repetição consecutiva
     * não é colapsada — F5/duplo clique é sinal de diagnóstico), sem
     * contagem (a contagem por path vive em `monitor_page_hits`).
     * `created_at` é o início da visita; `updated_at` é a última atividade
     * (não o "fim" — o servidor não sabe quando o usuário saiu).
     *
     * `scraper` é sticky-true: vira true quando algum request da visita foi
     * flagged pelo ScraperSignalDetector e não volta a false, pra que
     * análises de funil possam filtrar `scraper = false` sem a visita
     * precisar ser descartada.
     */
    public function up(): void
    {
        Schema::create('monitor_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->json('paths');
            $table->boolean('scraper')->default(false);
            $table->timestamps();

            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_visits');
    }
};
