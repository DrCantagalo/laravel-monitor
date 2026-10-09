<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * laravel-monitor 317: liga/desliga, em runtime, o acesso REMOTO do
 * dashboard (monitor.cantagalo.it) a este site, via `php artisan
 * monitor:dashboard on|off|status` — o dono do site abre o acesso só
 * enquanto usa e fecha depois, sem mexer em código.
 *
 * Deliberadamente SEPARADO de `config('monitor.dashboard.enabled')`
 * (decisão de INSTALAÇÃO — "este site usa o dashboard" — que decide se a
 * rota `/monitor/handler` existe, código versionado) e de bloqueio de
 * IP/`MonitorMethod` (reputação de visitante, nada a ver com o dono do
 * site). Estado em `storage/monitor/dashboard-access.json` (ao lado de
 * `installation.json`), NUNCA em `config/monitor.php`: aquele arquivo é
 * código versionado do projeto cliente — deploy sobrescreve/deixa a
 * working tree suja, e mudar precisaria limpar cache de config. Lido a
 * cada request (sem cache) pra uma troca via comando ter efeito imediato
 * na próxima chamada do dashboard.
 *
 * Ausência do arquivo = acesso LIGADO — default seguro pra instalação
 * existente que nunca rodou `monitor:dashboard off` (não pode quebrar
 * quem já usa o dashboard hoje).
 */
class DashboardAccess
{
    protected static function path(): string
    {
        return storage_path('monitor/dashboard-access.json');
    }

    /**
     * Leitura crua do arquivo (sem resolver expiração) — `status()`/
     * `isEnabled()` decidem o que fazer com `expires_at`.
     *
     * @return array{state: string, expires_at: ?string}
     */
    protected static function read(): array
    {
        $path = self::path();

        if (! File::exists($path)) {
            return ['state' => 'on', 'expires_at' => null];
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data) || ! in_array($data['state'] ?? null, ['on', 'off'], true)) {
            return ['state' => 'on', 'expires_at' => null];
        }

        return [
            'state' => $data['state'],
            'expires_at' => is_string($data['expires_at'] ?? null) ? $data['expires_at'] : null,
        ];
    }

    protected static function write(string $state, ?Carbon $expiresAt): void
    {
        $dir = storage_path('monitor');

        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put(self::path(), json_encode([
            'state' => $state,
            'expires_at' => $expiresAt?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * `true` quando o acesso remoto está ligado AGORA — um `on --for=`
     * já vencido conta como desligado, checado aqui na leitura (nunca
     * dependente de um scheduler rodar a tempo pra "fechar sozinho").
     * Esta é a checagem usada por `MonitorController::handle()` a cada
     * request — sem cache de propósito (ver docblock da classe).
     */
    public static function isEnabled(): bool
    {
        return self::status()['state'] === 'on';
    }

    /**
     * Estado efetivo (já resolvendo expiração) + o `expires_at` original
     * (`null` se não houver prazo) — usado por `isEnabled()` e pelo
     * comando `monitor:dashboard status`.
     *
     * @return array{state: string, expires_at: ?Carbon}
     */
    public static function status(): array
    {
        $raw = self::read();
        $expiresAt = $raw['expires_at'] !== null ? Carbon::parse($raw['expires_at']) : null;

        $state = $raw['state'];

        if ($state === 'on' && $expiresAt !== null && $expiresAt->isPast()) {
            $state = 'off';
        }

        return ['state' => $state, 'expires_at' => $expiresAt];
    }

    public static function enable(?Carbon $until = null): void
    {
        self::write('on', $until);
    }

    public static function disable(): void
    {
        self::write('off', null);
    }

    /**
     * Parseia `--for=2h`/`--for=30m`/`--for=1d` (inteiro positivo +
     * unidade `h`/`m`/`d`) em minutos. `null` pra qualquer formato fora
     * disso — fail-loud: quem chama decide como rejeitar, nunca um
     * default silencioso pra valor inválido (regra do usuário).
     */
    public static function parseForToMinutes(string $value): ?int
    {
        if (! preg_match('/^(\d+)(h|m|d)$/', trim($value), $matches)) {
            return null;
        }

        $amount = (int) $matches[1];

        if ($amount <= 0) {
            return null;
        }

        return match ($matches[2]) {
            'm' => $amount,
            'h' => $amount * 60,
            'd' => $amount * 60 * 24,
        };
    }
}
