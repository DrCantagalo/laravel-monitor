<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            // Origem do bloqueio: 'manual' (dashboard) hoje; deixado livre
            // pra no futuro aceitar 'collective' (blacklist opt-in entre
            // sites) ou o nome de um feed externo de reputação (ex:
            // 'abuseipdb', 'crowdsec') sem precisar migration nova.
            $table->string('source')->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_blocked_ips');
    }
};
