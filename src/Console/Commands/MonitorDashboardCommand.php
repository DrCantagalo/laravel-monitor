<?php

namespace Drcantagalo\LaravelMonitor\Console\Commands;

use Drcantagalo\LaravelMonitor\Support\DashboardAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * laravel-monitor 317: `php artisan monitor:dashboard on|off|status` — o
 * dono do site cliente abre o acesso REMOTO do dashboard
 * (monitor.cantagalo.it) só enquanto usa e fecha depois, sem mexer em
 * código/deploy. Ver docblock de `Support\DashboardAccess` pra como isso
 * difere de `config('monitor.dashboard.enabled')`.
 *
 * Mesmo padrão de idioma de `MonitorUpdateCommand` (roda repetido, ao
 * contrário de `monitor:install` que só pergunta uma vez): lê
 * `storage/monitor/installation.json` (`lang`), fallback `en`.
 */
class MonitorDashboardCommand extends Command
{
    protected $signature = 'monitor:dashboard {state=status : on|off|status} {--for= : Only with "on" — keep access open for a limited duration (e.g. 2h, 30m, 1d); access closes by itself once it expires}';

    protected $description = "Turn the hosted dashboard's remote access to this site on/off at runtime, independent of config('monitor.dashboard.enabled')";

    protected string $lang = 'en';

    protected array $translations = [
        'en' => [
            'invalid_state' => 'Invalid state: must be "on", "off" or "status".',
            'for_only_with_on' => '--for can only be used with "on".',
            'invalid_for' => 'Invalid --for value (expected an integer followed by m, h or d, e.g. 30m, 2h, 1d):',
            'turned_off' => '🔒 Dashboard remote access turned OFF. The site keeps tracking/blocking/pruning locally; only the dashboard (monitor.cantagalo.it) is denied.',
            'turned_on' => '🔓 Dashboard remote access turned ON (no expiration).',
            'turned_on_for' => '🔓 Dashboard remote access turned ON until',
            'status_off' => '🔒 Dashboard remote access is OFF.',
            'status_on' => '🔓 Dashboard remote access is ON (no expiration).',
            'status_on_until' => '🔓 Dashboard remote access is ON until',
        ],
        'it' => [
            'invalid_state' => 'Stato non valido: deve essere "on", "off" o "status".',
            'for_only_with_on' => '--for può essere usato solo con "on".',
            'invalid_for' => 'Valore --for non valido (previsto un intero seguito da m, h o d, es: 30m, 2h, 1d):',
            'turned_off' => "🔒 Accesso remoto alla dashboard DISATTIVATO. Il sito continua a tracciare/bloccare/pulire localmente; solo la dashboard (monitor.cantagalo.it) viene negata.",
            'turned_on' => '🔓 Accesso remoto alla dashboard ATTIVATO (senza scadenza).',
            'turned_on_for' => '🔓 Accesso remoto alla dashboard ATTIVATO fino a',
            'status_off' => '🔒 L\'accesso remoto alla dashboard è DISATTIVATO.',
            'status_on' => '🔓 L\'accesso remoto alla dashboard è ATTIVATO (senza scadenza).',
            'status_on_until' => '🔓 L\'accesso remoto alla dashboard è ATTIVATO fino a',
        ],
        'pt' => [
            'invalid_state' => 'Estado inválido: precisa ser "on", "off" ou "status".',
            'for_only_with_on' => '--for só pode ser usado com "on".',
            'invalid_for' => 'Valor de --for inválido (esperado um inteiro seguido de m, h ou d, ex: 30m, 2h, 1d):',
            'turned_off' => '🔒 Acesso remoto do dashboard DESLIGADO. O site continua rastreando/bloqueando/podando localmente; só o dashboard (monitor.cantagalo.it) é negado.',
            'turned_on' => '🔓 Acesso remoto do dashboard LIGADO (sem prazo).',
            'turned_on_for' => '🔓 Acesso remoto do dashboard LIGADO até',
            'status_off' => '🔒 O acesso remoto do dashboard está DESLIGADO.',
            'status_on' => '🔓 O acesso remoto do dashboard está LIGADO (sem prazo).',
            'status_on_until' => '🔓 O acesso remoto do dashboard está LIGADO até',
        ],
    ];

    public function handle(): int
    {
        $this->lang = $this->resolveLang();

        $state = $this->argument('state');

        if (! in_array($state, ['on', 'off', 'status'], true)) {
            $this->error($this->t('invalid_state'));

            return 1;
        }

        if ($state === 'status') {
            return $this->showStatus();
        }

        $forRaw = $this->option('for');

        if ($state === 'off') {
            if ($forRaw !== null) {
                $this->error($this->t('for_only_with_on'));

                return 1;
            }

            DashboardAccess::disable();
            $this->info($this->t('turned_off'));

            return 0;
        }

        $until = null;

        if ($forRaw !== null) {
            $minutes = DashboardAccess::parseForToMinutes($forRaw);

            if ($minutes === null) {
                $this->error($this->t('invalid_for').' '.$forRaw);

                return 1;
            }

            $until = now()->addMinutes($minutes);
        }

        DashboardAccess::enable($until);

        if ($until) {
            $this->info($this->t('turned_on_for').' '.$until->toIso8601String());
        } else {
            $this->info($this->t('turned_on'));
        }

        return 0;
    }

    protected function showStatus(): int
    {
        $status = DashboardAccess::status();

        if ($status['state'] === 'off') {
            $this->info($this->t('status_off'));

            return 0;
        }

        if ($status['expires_at'] !== null) {
            $this->info($this->t('status_on_until').' '.$status['expires_at']->toIso8601String());

            return 0;
        }

        $this->info($this->t('status_on'));

        return 0;
    }

    /**
     * Mesmo esquema de `MonitorUpdateCommand::resolveLang()` — lê
     * `storage/monitor/installation.json` (`lang`), fallback `en` se o
     * arquivo não existir, não tiver a chave, ou ela não for um idioma
     * suportado.
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
}
