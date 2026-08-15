<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_blocked_paths', function (Blueprint $table) {
            $table->id();
            // Sempre sem host (a parte "variável" do path, ex:
            // "wp-admin/install.php") - protege TODOS os hosts que
            // compartilham a mesma instalação do pacote (ver comentário em
            // MonitorMethod sobre o prefixo de host em `data.page`), já
            // que o match no middleware é feito só contra o path.
            $table->string('path')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_blocked_paths');
    }
};
