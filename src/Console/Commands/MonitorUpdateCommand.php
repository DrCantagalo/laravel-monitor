<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Composer\InstalledVersions;
use Drcantagalo\LaravelMonitor\Support\DomainNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Sincroniza a cópia publicada de config/monitor.php (vendor:publish
 * --tag=monitor-config, uma cópia estática no projeto host) com o template
 * atual do pacote. `mergeConfigFrom()` (MonitorServiceProvider::register())
 * já cobre chaves *ausentes* na cópia publicada em runtime, mas não
 * persiste isso no arquivo nem atualiza chaves já existentes com valor
 * desatualizado (ex: 'version') — este comando faz essa manutenção,
 * pra ser rodado depois de `composer update drcantagalo/laravel-monitor`.
 */
class MonitorUpdateCommand extends Command
{
    protected $signature = 'monitor:update';

    protected $description = 'Atualiza config/monitor.php publicado com as chaves novas do pacote, sem tocar em customizações existentes';

    /**
     * Ao contrário de monitor:install (roda uma vez só), monitor:update
     * roda repetido a cada `composer update` — perguntar o idioma toda
     * vez seria ruim. O idioma vem de storage/monitor/installation.json
     * (['lang'], persistido por monitor:install desde que essa própria
     * chave passou a existir) via resolveLang(); fallback 'en' pra
     * instalação feita antes disso ou sem installation.json.
     */
    protected array $translations = [
        'en' => [
            'not_published' => "config/monitor.php is not published in this project. Run 'php artisan vendor:publish --tag=monitor-config' first.",
            'updated' => 'Laravel Monitor: config/monitor.php updated.',
            'new_keys' => 'New keys added: ',
            'no_new_keys' => 'No new keys.',
            'version_updated' => 'Version updated: ',
            'none' => '(none)',
            'stale_keys' => "Custom keys with a value different from the package's current default (not changed, manual decision):",
            'removed_keys' => 'Keys present in your config but removed/renamed in the current package template (check the CHANGELOG):',
            'pending_migrations' => 'Package migrations not yet applied:',
            'confirm_migrate' => 'Can we run the pending migrations now?',
            'ask_main_domain' => "Enter your site's main domain, without scheme (e.g., example.com). Any subdomain of it (e.g., app.example.com) is treated as part of your own site, never as a referral source.",
            'invalid_domain' => "That doesn't look like a valid domain. Please try again.",
        ],
        'it' => [
            'not_published' => "config/monitor.php non è pubblicato in questo progetto. Esegui prima 'php artisan vendor:publish --tag=monitor-config'.",
            'updated' => 'Laravel Monitor: config/monitor.php aggiornato.',
            'new_keys' => 'Nuove chiavi aggiunte: ',
            'no_new_keys' => 'Nessuna chiave nuova.',
            'version_updated' => 'Versione aggiornata: ',
            'none' => '(nessuna)',
            'stale_keys' => 'Chiavi personalizzate con un valore diverso dal default attuale del pacchetto (non modificate, decisione manuale):',
            'removed_keys' => 'Chiavi presenti nella tua config ma rimosse/rinominate nel template attuale del pacchetto (controlla il CHANGELOG):',
            'pending_migrations' => 'Migrazioni del pacchetto ancora non applicate:',
            'confirm_migrate' => 'Possiamo eseguire ora le migrazioni pendenti?',
            'ask_main_domain' => 'Inserisci il dominio principale del tuo sito, senza schema (es: example.com). Qualsiasi sottodominio (es: app.example.com) verrà considerato parte del tuo sito, non come fonte di referral.',
            'invalid_domain' => 'Non sembra un dominio valido. Riprova.',
        ],
        'pt' => [
            'not_published' => "config/monitor.php não está publicado neste projeto. Rode 'php artisan vendor:publish --tag=monitor-config' primeiro.",
            'updated' => 'Laravel Monitor: config/monitor.php atualizado.',
            'new_keys' => 'Chaves novas adicionadas: ',
            'no_new_keys' => 'Nenhuma chave nova.',
            'version_updated' => 'Versão atualizada: ',
            'none' => '(nenhuma)',
            'stale_keys' => 'Chaves customizadas com valor diferente do default atual do pacote (não alteradas, decisão manual):',
            'removed_keys' => 'Chaves presentes na sua config mas removidas/renomeadas no template atual do pacote (confira o CHANGELOG):',
            'pending_migrations' => 'Migrations do pacote ainda não aplicadas:',
            'confirm_migrate' => 'Podemos rodar as migrations pendentes agora?',
            'ask_main_domain' => 'Informe o domínio principal do seu site, sem o esquema (ex: meusite.com). Qualquer subdomínio dele (ex: app.meusite.com) será tratado como parte do mesmo site, nunca como origem de referência.',
            'invalid_domain' => 'Isso não parece um domínio válido. Tente novamente.',
        ],
    ];

    protected string $lang = 'en';

    public function handle(): int
    {
        $this->lang = $this->resolveLang();
        $this->ensureMainDomain();

        $publishedPath = config_path('monitor.php');
        $templatePath = __DIR__.'/../../config/monitor.php';

        if (! File::exists($publishedPath)) {
            $this->warn($this->t('not_published'));

            return 1;
        }

        $published = require $publishedPath;
        $template = require $templatePath;

        $addedKeys = array_values(array_diff(array_keys($template), array_keys($published)));
        $removedKeys = array_values(array_diff(array_keys($published), array_keys($template)));

        $staleKeys = [];
        foreach ($published as $key => $value) {
            if ($key === 'version' || ! array_key_exists($key, $template)) {
                continue;
            }
            if ($template[$key] !== $value) {
                $staleKeys[] = $key;
            }
        }

        $source = File::get($publishedPath);

        if ($addedKeys !== []) {
            $source = $this->appendKeys($source, $templatePath, $addedKeys);
        }

        $oldVersion = $published['version'] ?? null;
        $newVersion = $this->resolveInstalledVersion($template);
        $source = $this->updateVersion($source, $newVersion);

        File::put($publishedPath, $source);

        $this->summarize($addedKeys, $staleKeys, $removedKeys, $oldVersion, $newVersion);
        $this->confirmPendingMigrations();

        return 0;
    }

    /**
     * `composer update` só atualiza os arquivos do pacote em vendor/ — não
     * roda migrate. As migrations em si são resolvidas automaticamente
     * (`loadMigrationsFrom()` no MonitorServiceProvider, sem precisar
     * publicar), mas aplicá-las no banco ainda depende de alguém rodar
     * `php artisan migrate`. Mesmo padrão de `monitor:install` (pergunta
     * antes de migrar, default sim) — em vez de só avisar, oferece rodar
     * na hora.
     */
    protected function confirmPendingMigrations(): void
    {
        $migrator = $this->laravel['migrator'];

        if (! $migrator->repositoryExists()) {
            return;
        }

        $files = $migrator->getMigrationFiles(__DIR__.'/../../database/migrations');
        $ran = $migrator->getRepository()->getRan();
        $pending = array_values(array_diff(array_keys($files), $ran));

        if ($pending === []) {
            return;
        }

        $this->newLine();
        $this->warn($this->t('pending_migrations'));
        foreach ($pending as $migration) {
            $this->line("  - {$migration}");
        }

        if ($this->confirm($this->t('confirm_migrate'), true)) {
            $this->call('migrate');
        }
    }

    /**
     * Lê o idioma persistido por MonitorInstallCommand em
     * storage/monitor/installation.json (['lang']). Fallback 'en' se o
     * arquivo não existir, não tiver a chave (instalação feita antes
     * desta task) ou não for um JSON válido.
     */
    protected function resolveLang(): string
    {
        $configFile = storage_path('monitor/installation.json');

        if (! File::exists($configFile)) {
            return 'en';
        }

        $config = json_decode(File::get($configFile), true);
        $lang = $config['lang'] ?? null;

        return array_key_exists($lang, $this->translations) ? $lang : 'en';
    }

    protected function t(string $key): string
    {
        return $this->translations[$this->lang][$key];
    }

    /**
     * task 345: migra installs existentes (ex: cantagalo.it, ed.eco.br)
     * que nunca ganharam `main_domain` porque foram instaladas antes
     * dessa chave existir - mesma pergunta/default/normalização de
     * `MonitorInstallCommand::askMainDomain()`. Não pergunta de novo se a
     * chave já existe (comando roda repetido a cada `composer update`).
     */
    protected function ensureMainDomain(): void
    {
        $configFile = storage_path('monitor/installation.json');
        $config = File::exists($configFile) ? (json_decode(File::get($configFile), true) ?? []) : [];

        $current = $config['main_domain'] ?? null;

        if (is_string($current) && $current !== '') {
            return;
        }

        $default = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? '');

        do {
            $answer = $this->ask($this->t('ask_main_domain'), $default !== '' ? $default : null);
            $normalized = DomainNormalizer::normalize($answer);

            if ($normalized === null) {
                $this->warn($this->t('invalid_domain'));
            }
        } while ($normalized === null);

        $config['main_domain'] = $normalized;

        $storagePath = storage_path('monitor');
        if (! File::exists($storagePath)) {
            File::makeDirectory($storagePath, 0755, true);
        }

        File::put($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    /**
     * Extrai do template, em blocos separados por linha em branco (cada
     * bloco = comentário(s) + definição de uma chave), os blocos das
     * chaves em $keys, e os insere antes do `];` final de $source —
     * preserva os comentários do template, que documentam cada opção.
     */
    protected function appendKeys(string $source, string $templatePath, array $keys): string
    {
        $templateSource = File::get($templatePath);
        $body = $templateSource;
        $body = substr($body, strpos($body, 'return [') + strlen('return ['));
        $body = substr($body, 0, strrpos($body, '];'));

        $chunks = preg_split('/\n[ \t]*\n/', trim($body, "\n"));

        $blocks = [];
        foreach ($chunks as $chunk) {
            if (preg_match("/'(\w+)'\s*=>/", $chunk, $m) && in_array($m[1], $keys, true)) {
                $blocks[$m[1]] = trim($chunk, "\n");
            }
        }

        // $keys já vem na ordem do template (array_diff preserva a ordem
        // do primeiro array, e quem monta $keys é array_diff(template, published)).
        $ordered = array_values(array_filter($keys, fn ($key) => isset($blocks[$key])));
        $insertion = implode("\n\n", array_map(fn ($key) => $blocks[$key], $ordered));

        if ($insertion === '') {
            return $source;
        }

        return preg_replace('/\n\];\s*\z/', "\n\n".$insertion."\n\n];\n", $source);
    }

    protected function updateVersion(string $source, ?string $newVersion): string
    {
        if ($newVersion === null) {
            return $source;
        }

        $updated = preg_replace(
            "/'version'\s*=>\s*'[^']*',/",
            "'version' => '{$newVersion}',",
            $source,
            1,
            $count
        );

        return $count > 0 ? $updated : $source;
    }

    protected function resolveInstalledVersion(array $template): ?string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('drcantagalo/laravel-monitor')) {
            $pretty = InstalledVersions::getPrettyVersion('drcantagalo/laravel-monitor');

            if ($pretty !== null) {
                return ltrim($pretty, 'v');
            }
        }

        return $template['version'] ?? null;
    }

    protected function summarize(array $addedKeys, array $staleKeys, array $removedKeys, ?string $oldVersion, ?string $newVersion): void
    {
        $this->info($this->t('updated'));

        if ($addedKeys !== []) {
            $this->line($this->t('new_keys').implode(', ', $addedKeys));
        } else {
            $this->line($this->t('no_new_keys'));
        }

        if ($oldVersion !== $newVersion && $newVersion !== null) {
            $this->line($this->t('version_updated').($oldVersion ?? $this->t('none'))." -> {$newVersion}");
        }

        if ($staleKeys !== []) {
            $this->warn($this->t('stale_keys'));
            foreach ($staleKeys as $key) {
                $this->line("  - {$key}");
            }
        }

        if ($removedKeys !== []) {
            $this->warn($this->t('removed_keys'));
            foreach ($removedKeys as $key) {
                $this->line("  - {$key}");
            }
        }
    }
}
