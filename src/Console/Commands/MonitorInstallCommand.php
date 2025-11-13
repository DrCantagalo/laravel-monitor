<?php

namespace Monitor\Console\Commands;

use Illuminate\Console\Command;
use Monitor\Services\PackageRegistrationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MonitorInstallCommand extends Command
{
    protected $signature = 'monitor:install';
    protected $translations = [
        'en' => [
            'start' => "🚀 Starting Laravel Monitor installation...",
            //'checking' => "🔍 Checking domain...",
            //'success' => "✅ Package successfully registered!",
            //'dns_warn' => "⚠️ Domain verification required! Add this TXT record:",
            'terms_notice' => "Before continuing, please read and accept the Terms of Use:",
            'accept_terms' => "Do you accept the Terms of Use?",
            'denied_terms' => "Installation cancelled. Please review the terms before proceeding.",
            'ask_url' => "Enter your site URL (e.g., https://example.com)",
            'hash_found' => "Existing installation hash found.",
            'hash_created' => "New installation hash generated.",
        ],
        'it' => [
            'start' => "🚀 Avvio dell'installazione di Laravel Monitor...",
            //'checking' => "🔍 Verifica del dominio...",
            //'success' => "✅ Pacchetto registrato con successo!",
            //'dns_warn' => "⚠️ Verifica del dominio richiesta! Aggiungi questo record TXT:",
            'terms_notice' => "Prima di continuare, leggi e accetta i Termini di utilizzo:",
            'accept_terms' => "Accetti i Termini di utilizzo?",
            'denied_terms' => "Installazione annullata. Si prega di leggere i termini prima di procedere.",
            'ask_url' => "Inserisci l'URL del tuo sito (es: https://example.com)",
            'hash_found' => "È stato trovato l'hash di installazione esistente.",
            'hash_created' => "Generato nuovo hash di installazione.",
        ],
        'pt' => [
            'start' => "🚀 Iniciando instalação do Laravel Monitor...",
            //'checking' => "🔍 Verificando domínio...",
            //'success' => "✅ Pacote registrado com sucesso!",
            //'dns_warn' => "⚠️ Verificação de domínio necessária! Adicione este registro TXT:",
            'terms_notice' => "Antes de continuar, leia e aceite os Termos de Uso:",
            'accept_terms' => "Você aceita os Termos de Uso?",
            'denied_terms' => "Instalação cancelada. Por favor, revise os termos antes de prosseguir.",
            'ask_url' => "Informe a URL pública do seu site (ex: https://meusite.com)",
            'hash_found' => "Hash de instalação existente encontrado.",
            'hash_created' => "Novo hash de instalação gerado.",
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
        $this->line('👉 https://monitor.cantagalo.it/installationterms/' . $lang);
        $accept = $this->confirm($t('accept_terms'), true);

        if (!$accept) {
            $this->warn($t('denied_terms'));
            return 1;
        }

        $siteUrl = $this->ask($t('ask_url'));
        config('monitor.version', '1.0.0');

        $hashFile = storage_path('monitor_installation_hash.txt');

        if (File::exists($hashFile)) {
            $installationHash = File::get($hashFile);
            $this->info($t('hash_found'));
        } else {
            $installationHash = hash('sha256', config('app.key') . Str::uuid());
            File::put($hashFile, $installationHash);
            $this->info($t('hash_created'));
        }

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
