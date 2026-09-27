<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_access_logs', function (Blueprint $table) {
            $table->id();
            // dateTime (não timestamp): convenção MySQL do pacote — a
            // segunda coluna timestamp sem nullable() recebe default
            // legado '0000-00-00' (erro 1067 em modo strict).
            $table->dateTime('accessed_at');
            $table->string('kind');
            $table->string('action');
            $table->string('ip');
            $table->string('user_agent')->nullable();
            $table->string('origin')->nullable();
            // Hash curto do read-token, nunca o token em si — liga a
            // linha de emissão (issueReadToken) à do primeiro uso
            // (read_token_first_use) do mesmo token. Ver Support\AccessLogger::tokenRef().
            $table->string('token_ref')->nullable();
            $table->string('declared_by')->nullable();
            $table->timestamps();

            $table->index('accessed_at');
            $table->index('token_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_access_logs');
    }
};
