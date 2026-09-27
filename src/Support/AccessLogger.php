<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\MonitorAccessLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * laravel-monitor 249: grava `monitor_access_logs` a partir dos três
 * pontos de `MonitorController::handle()` que leem dados do cliente
 * (emissão de read-token, primeiro uso de um read-token pelo navegador,
 * leitura com o local_token permanente). Motivação: transparência — o
 * cliente pode auditar (`monitor:access-log`) quem/o que leu os dados
 * dele, num log que só existe no próprio servidor dele (nunca replicado
 * pro nosso lado).
 *
 * Fail-open (mesmo padrão do resto do pacote, ex. `DataPruner`): uma
 * installation que ainda não rodou a migration desta task não pode ter
 * NENHUMA action de leitura derrubada só porque o log não pôde ser
 * gravado.
 */
class AccessLogger
{
    public const KIND_READ_TOKEN_ISSUED = 'read_token_issued';

    public const KIND_READ_TOKEN_FIRST_USE = 'read_token_first_use';

    public const KIND_LOCAL_TOKEN_READ = 'local_token_read';

    /**
     * Nunca o token em si — só o suficiente pra ligar visualmente a linha
     * de emissão (`issueReadToken`) à do primeiro uso
     * (`read_token_first_use`) do mesmo token no dashboard.
     */
    public static function tokenRef(string $token): string
    {
        return substr(hash('sha256', $token), 0, 16);
    }

    public static function record(
        string $kind,
        string $action,
        string $ip,
        ?string $userAgent = null,
        ?string $origin = null,
        ?string $tokenRef = null,
        ?string $declaredBy = null,
    ): void {
        try {
            MonitorAccessLog::create([
                'accessed_at' => now(),
                'kind' => $kind,
                'action' => $action,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'origin' => $origin,
                'token_ref' => $tokenRef,
                'declared_by' => $declaredBy !== null && $declaredBy !== ''
                    ? Str::limit($declaredBy, 190, '')
                    : null,
            ]);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] falha ao gravar monitor_access_logs (rode `php artisan migrate` ou `php artisan monitor:update`?). Erro original: '.$e->getMessage());
        }
    }
}
