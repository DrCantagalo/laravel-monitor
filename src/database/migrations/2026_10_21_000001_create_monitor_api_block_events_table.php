<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 400, item 5: um IP bloqueado (`monitor_blocked_ips`)
     * que ainda carrega um token Sanctum VÁLIDO continua recebendo 403 —
     * mas isso é um evento distinto de um scraper anônimo batendo num
     * endpoint bloqueado: alguém com credencial válida está vindo de um
     * IP já bloqueado. Esta tabela registra especificamente esse evento
     * ("request bloqueada com token válido") pro dashboard (task 401,
     * fora do escopo desta) poder exibir/investigar — `MonitorApiMethod`
     * é o único gravador.
     *
     * Append-only (mesmo espírito de `monitor_access_logs`): nenhuma
     * action HTTP deste pacote edita ou apaga linhas daqui.
     *
     * `monitor_id` fica de fora de propósito: o bloqueio acontece ANTES
     * de qualquer tracking (mesma ordem de `MonitorMethod` — bloqueio
     * nunca cria/atualiza um Monitor), então não existe um Monitor pra
     * ligar aqui. `user_id`/`token_id`/`token_name` são capturados crus
     * (sem FK pra `personal_access_tokens`, que é tabela do Sanctum, não
     * deste pacote — mesmo raciocínio de `token_id` em `monitors`).
     */
    public function up(): void
    {
        Schema::create('monitor_api_block_events', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45);
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('token_name')->nullable();
            $table->string('user_id', 64)->nullable();
            $table->string('message');
            $table->timestamps();

            $table->index('ip');
            $table->index('token_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_api_block_events');
    }
};
