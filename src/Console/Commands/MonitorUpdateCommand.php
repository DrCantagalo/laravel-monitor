<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Composer\InstalledVersions;
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

    public function handle(): int
    {
        $publishedPath = config_path('monitor.php');
        $templatePath = __DIR__.'/../../config/monitor.php';

        if (! File::exists($publishedPath)) {
            $this->warn("config/monitor.php não está publicado neste projeto. Rode 'php artisan vendor:publish --tag=monitor-config' primeiro.");

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
        $this->warn('Migrations do pacote ainda não aplicadas:');
        foreach ($pending as $migration) {
            $this->line("  - {$migration}");
        }

        if ($this->confirm('Podemos rodar as migrations pendentes agora?', true)) {
            $this->call('migrate');
        }
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
        $this->info('Laravel Monitor: config/monitor.php atualizado.');

        if ($addedKeys !== []) {
            $this->line('Chaves novas adicionadas: '.implode(', ', $addedKeys));
        } else {
            $this->line('Nenhuma chave nova.');
        }

        if ($oldVersion !== $newVersion && $newVersion !== null) {
            $this->line('Versão atualizada: '.($oldVersion ?? '(nenhuma)')." -> {$newVersion}");
        }

        if ($staleKeys !== []) {
            $this->warn('Chaves customizadas com valor diferente do default atual do pacote (não alteradas, decisão manual):');
            foreach ($staleKeys as $key) {
                $this->line("  - {$key}");
            }
        }

        if ($removedKeys !== []) {
            $this->warn('Chaves presentes na sua config mas removidas/renomeadas no template atual do pacote (confira o CHANGELOG):');
            foreach ($removedKeys as $key) {
                $this->line("  - {$key}");
            }
        }
    }
}
