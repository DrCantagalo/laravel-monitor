<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_ip_stats', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            $table->unsignedInteger('visit_count')->default(1);
            $table->timestamp('first_seen')->useCurrent();
            $table->timestamp('last_seen')->useCurrent();
            $table->boolean('flagged')->default(false);
            // Sinais do ScraperSignalDetector no request mais recente
            // desse IP (mesmo array que data.flags.scraper_signals no
            // Monitor) - null enquanto nenhum sinal disparou.
            $table->json('flagged_signals')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_ip_stats');
    }
};
