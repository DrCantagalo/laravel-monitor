<?php

namespace Monitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MonitorInstallCommand extends Command
{
    protected $signature = 'monitor:install';
    public $lang;
    protected $translations = [
        'en' => [
            'start' => "🚀 Starting Laravel Monitor installation...",
            'terms_notice' => "Before continuing, please read and accept the Terms of Use:",
            'accept_terms' => "Do you accept the Terms of Use?",
            'denied_terms' => "Installation cancelled. Please review the terms before proceeding.",
            'pub_config' => "Can we publish the package configuration archive? (If not, you will need to publish it manually later).",
            'pub_migration' => "Can we publish the package migration archive? (If not, you will need to publish it manually later).",
            'migrate' => "Can we migrate the package's Eloquent model to your database? (If not, you will need to migrate it manually later).",
            'ask_url' => "Enter your site URL (e.g., https://example.com)",
            'hash_found' => "Existing installation configuration found.",
            'hash_created' => "New installation configuration created.",
            'gitignore' => "Would you like to add storage/monitor/installation.json to your .gitignore?",
            'checking' => "🔍 Checking domain...",
            'error' => "❌ Error: There was a problem registering the package.",
            'installation_code' => "Installation completed successfully. Here is your installation code; you will need it to create your account at https://monitor.cantagalo.it: "
        ],
        'it' => [
            'start' => "🚀 Avvio dell'installazione di Laravel Monitor...",
            'terms_notice' => "Prima di continuare, leggi e accetta i Termini di utilizzo:",
            'accept_terms' => "Accetti i Termini di utilizzo?",
            'denied_terms' => "Installazione annullata. Si prega di leggere i termini prima di procedere.",
            'pub_config' => "Possiamo pubblicare l'archivio di configurazione del pacchetto? (In caso contrario, sarà necessario pubblicarlo manualmente in seguito).",
            'pub_migration' => "Possiamo pubblicare l'archivio di migrazione del pacchetto? (In caso contrario, sarà necessario pubblicarlo manualmente in seguito).",
            'migrate' => "Possiamo migrare il modello Eloquent del pacchetto nel tuo database? (In caso contrario, dovrai migrarlo manualmente in seguito).",
            'ask_url' => "Inserisci l'URL del tuo sito (es: https://example.com)",
            'hash_found' => "Trovata configurazione di installazione esistente.",
            'hash_created' => "Nuova configurazione di installazione creata.",
            'gitignore' => "Vuoi aggiungere storage/monitor/installation.json al tuo file .gitignore?",
            'checking' => "🔍 Verifica del dominio...",
            'error' => "❌ Errore: si è verificato un problema durante la registrazione del pacchetto.",
            'installation_code' => "Installazione completata con successo. Ecco il tuo codice di installazione; ti servirà per creare il tuo account su https://monitor.cantagalo.it: "
        ],
        'pt' => [
            'start' => "🚀 Iniciando instalação do Laravel Monitor...",
            'terms_notice' => "Antes de continuar, leia e aceite os Termos de Uso:",
            'accept_terms' => "Você aceita os Termos de Uso?",
            'denied_terms' => "Instalação cancelada. Por favor, revise os termos antes de prosseguir.",
            'pub_config' => "Podemos publicar o arquivo de configuração do pacote? (Caso contrário, você precisará publicá-lo manualmente mais tarde).",
            'pub_migration' => "Podemos publicar o arquivo de migração do pacote? (Caso contrário, você precisará publicá-lo manualmente mais tarde).",
            'migrate' => "Podemos migrar o modelo Eloquent do pacote para o seu banco de dados? (Caso contrário, você precisará migrá-lo manualmente mais tarde).",
            'ask_url' => "Informe a URL pública do seu site (ex: https://meusite.com)",
            'hash_found' => "Configuração de instalação existente encontrada.",
            'hash_created' => "Nova configuração de instalação criada.",
            'gitignore' => "Deseja adicionar o arquivo storage/monitor/installation.json ao seu .gitignore?",
            'checking' => "🔍 Verificando domínio...",
            'error' => "❌ Erro: Ocorreu um problema ao registrar o pacote.",
            'installation_code' => "Instalação concluída com sucesso. Aqui está o seu código de instalação; você precisará dele para criar sua conta em https://monitor.cantagalo.it: "
        ],
    ];

    public function handle()
    {   
        $langChoice = $this->choice('Choose your language / Scegli la lingua / Escolha o idioma', ['en', 'it', 'pt'], 0);
        $this->lang = $langChoice;
        $t = fn($key) => $this->translations[$this->lang][$key];
        
        $this->info($t('start'));
        $this->newLine();

        $this->info($t('terms_notice'));
        $this->line('👉 https://monitor.cantagalo.it/installationterms/' . $this->lang);
        $accept = $this->confirm($t('accept_terms'), true);

        if (!$accept) {
            $this->warn($t('denied_terms'));
            return 1;
        }
        
        $pub_config = $this->confirm($t('pub_config'), true);

        if ($pub_config) {
            $this->call('vendor:publish', ['--tag' => 'monitor-config']);
        }
        
        $pub_migration = $this->confirm($t('pub_migration'), true);

        if ($pub_migration) {
            $this->call('vendor:publish', ['--tag' => 'monitor-migrations']);
        }       

        $migrate = $this->confirm($t('migrate'), true);

        if ($migrate) {
            $this->call('migrate');
        }

        $siteUrl = $this->ask($t('ask_url'));

        $storagePath = storage_path('monitor');
        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        $configFile = $storagePath . '/installation.json';

        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $installationHash = $config['installation_hash'];
            $localToken = $config['local_token'];
            $this->info($t('hash_found'));
        } else {
            $installationHash = hash('sha256', config('app.key') . Str::uuid());
            $localToken = Str::random(64);

            $config = [
                'installation_hash' => $installationHash,
                'local_token' => $localToken,
            ];
            File::put($configFile, json_encode($config, JSON_PRETTY_PRINT));
            $this->info($t('hash_created'));
        }

        $gitignore = $this->confirm($t('gitignore'), true);

        if ($gitignore) {
            File::append(base_path('.gitignore'), "\n# Laravel Monitor\nstorage/monitor/installation.json\n");
        }

        $this->info($t('checking'));
        
        $response = Http::post('https://cantagalo.it/registerinstallation', [
            'lang' => $this->lang,
            'installation_hash' => $installationHash,
            'site_url' => $siteUrl,
            'package_version' => config('monitor.version'),
            'sanctum_token' => $localToken,
        ]);

        $data = $response->json();

        if (isset($data['message'])) {
            $this->info($data['message']);
            if ($data['status'] == 'success') {
                $config = json_decode(File::get($configFile), true);
                $config['external_token'] = $data['api_token'];
                $config['installation_code'] = $data['installation_code'];
                $config['installed_at'] = now()->toDateTimeString();
                File::put($configFile, json_encode($config, JSON_PRETTY_PRINT));
                $this->line($t('installation_code') . $data['installation_code']);
            }
        }
        else {
            $this->error($t('error'));
        }

        return 0;
    }
}