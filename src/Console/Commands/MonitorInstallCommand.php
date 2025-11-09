<?php

namespace Monitor\Console\Commands;

use Illuminate\Console\Command;
use Monitor\Services\PackageRegistrationService;

class MonitorInstallCommand extends Command
{
    protected $signature = 'monitor:install';
    protected $translations = [
        'en' => [
            'start' => "🚀 Starting Laravel Monitor installation...",
            'ask_url' => "Enter your site URL (e.g., https://example.com)",
            'ask_version' => "Package version (press Enter for default)",
            'checking' => "🔍 Checking domain...",
            'success' => "✅ Package successfully registered!",
            'dns_warn' => "⚠️ Domain verification required! Add this TXT record:",
            'terms_notice' => "Before continuing, please read and accept the Terms of Use:",
        ],
        'it' => [
            'start' => "🚀 Avvio dell'installazione di Laravel Monitor...",
            'ask_url' => "Inserisci l'URL del tuo sito (es: https://example.com)",
            'ask_version' => "Versione del pacchetto (premi Invio per predefinita)",
            'checking' => "🔍 Verifica del dominio...",
            'success' => "✅ Pacchetto registrato con successo!",
            'dns_warn' => "⚠️ Verifica del dominio richiesta! Aggiungi questo record TXT:",
            'terms_notice' => "Prima di continuare, leggi e accetta i Termini di utilizzo:",
        ],
        'pt' => [
            'start' => "🚀 Iniciando instalação do Laravel Monitor...",
            'ask_url' => "Informe a URL pública do seu site (ex: https://meusite.com)",
            'ask_version' => "Versão do pacote (pressione Enter para padrão)",
            'checking' => "🔍 Verificando domínio...",
            'success' => "✅ Pacote registrado com sucesso!",
            'dns_warn' => "⚠️ Verificação de domínio necessária! Adicione este registro TXT:",
            'terms_notice' => "Antes de continuar, leia e aceite os Termos de Uso:",
        ],
    ];


    public function handle(PackageRegistrationService $registrationService)
    {
        $langChoice = $this->choice('Choose your language / Scegli la lingua / Escolha o idioma', ['en', 'it', 'pt'], 0);
        $this->lang = $langChoice;
        $t = fn($key) => $this->translations[$this->lang][$key];
        
        $this->info($t('start'));
        $this->newLine();

        $this->info($t('terms_notice'));
        $this->line('👉 https://monitor.cantagalo.it/installationterms');
        $accept = $this->confirm('Do you accept the Terms of Use?', true);

        if (!$accept) {
            $this->warn('Installation cancelled. Please review the terms before proceeding.');
            return 1;
        }

        $siteUrl = $this->ask('Informe a URL pública do seu site (ex: https://meusite.com)');
        $version = $this->ask('Versão do pacote (pressione Enter para usar a padrão)', config('monitor.version', '1.0.0'));

        $this->info('🔍 Verificando domínio...');
        $result = $registrationService->registerPackage($siteUrl, $version);

        if ($result['status'] === 'created') {
            $this->info("✅ Pacote registrado com sucesso!");
            $this->line("Installation Code: {$result['installation_code']}");
            $this->line("API Token: {$result['api_token']}");
        } elseif ($result['status'] === 'pending_dns') {
            $this->warn("⚠️ Verificação de domínio necessária!");
            $this->line("Adicione o registro TXT no seu DNS:");
            $this->line("_monitor.{$result['domain']} → {$result['expected_hash']}");
        } else {
            $this->error("❌ Erro: {$result['message']}");
        }

        return 0;
    }
}
