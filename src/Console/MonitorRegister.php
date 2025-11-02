<?php

namespace Monitor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class RegisterMonitor extends Command
{
    protected $signature = 'monitor:register';
    protected $description = 'Register this site with the Monitor SaaS';

    public function handle()
    {
        // 1. Gerar hash único
        $hash = Str::random(40);

        // 2. Enviar para o servidor SaaS
        $response = Http::post('https://seu-crm.com/api/webhook/register-site', [
            'site_url' => config('app.url'),
            'site_hash' => $hash,
            'package_version' => '1.0.0'
        ]);

        if ($response->successful()) {
            $token = $response->json('token');
            // 3. Salvar token localmente no config
            $this->info("Site registered successfully! Token: {$token}");
            // opcional: gravar no .env ou config/monitor.php
        } else {
            $this->error("Failed to register site. {$response->body()}");
        }
    }
}
