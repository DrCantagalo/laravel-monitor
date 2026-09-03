<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_blocked_ips', function (Blueprint $table) {
            // null = permanent (manual block via updateBlockedIps/blockIps
            // never sets this — stays permanent exactly like before this
            // migration). A non-null value in the past means the block has
            // expired; indexed since MonitorMethod::isBlocked() filters on
            // it on every request. Ver ScraperBlocker::registerOffense().
            $table->timestamp('blocked_until')->nullable()->index();

            // Strikes "quentes": decai com o tempo (ver
            // ScraperBlocker::registerOffense()), dirige só a DURAÇÃO do
            // bloqueio atual (2^strike_count horas). Default 1 é
            // irrelevante pra bloqueio manual (nunca populado por ele).
            $table->unsignedInteger('strike_count')->default(1);

            // Ofensas na vida inteira desse IP: NUNCA decai, só soma.
            // Dirige o threshold de permanente
            // (auto_block_permanent_after_lifetime_offenses), separado de
            // strike_count de propósito — um decaimento único sem teto
            // fazia qualquer reincidência espaçada em >= 1 cooldown
            // convergir pra sempre em strike_count=2, nunca alcançando o
            // permanente (visto discutindo com o usuário depois do
            // primeiro rascunho desta task). Este contador não tem esse
            // problema porque nunca decresce.
            $table->unsignedInteger('lifetime_offense_count')->default(1);

            // Timestamp da última ofensa registrada via registerOffense() —
            // usado tanto pro cálculo de decaimento quanto pela limpeza
            // (laravel-monitor 97).
            $table->timestamp('last_offense_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('monitor_blocked_ips', function (Blueprint $table) {
            $table->dropColumn(['blocked_until', 'strike_count', 'lifetime_offense_count', 'last_offense_at']);
        });
    }
};
