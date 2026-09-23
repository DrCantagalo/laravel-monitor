<?php

namespace Drcantagalo\LaravelMonitor\Support;

/**
 * laravel-monitor 152 (v0.46.0): ponto único de sanitização do blob `data`
 * de um `Monitor` antes de expô-lo em qualquer response de leitura
 * (`getUserVisits`/`hydrateUserVisitRows`, `getIpMonitors`) — extraído pra
 * não duplicar a mesma regra nos dois lugares.
 *
 * Hoje só tira `id-token`: até a laravel-monitor 141 (v0.42.0) o token de
 * remember-me vivia em `data['id-token']` (ver migration
 * `2026_09_21_000001_add_id_token_to_monitors_table`, que só LÊ essa chave
 * pra backfilar a coluna `id_token` nova, sem apagá-la do blob). Uma linha
 * criada antes daquela migration continua com a chave no JSON pra sempre —
 * sem esta sanitização, o valor cru do cookie de "lembrar-me" (efetivamente
 * uma credencial: quem tiver esse valor se autentica como aquele
 * visitante) vazava pra fora em qualquer response que devolvesse `data` de
 * uma linha antiga.
 *
 * A coluna `id_token` (nova, desde 0.42.0) NUNCA deve aparecer em resposta
 * nenhuma — isso não é responsabilidade desta classe (que só sanitiza o
 * blob `data`), e sim de cada `select()`/`toArray()` que monta a response
 * nunca incluir essa coluna no primeiro lugar (ver `getIpMonitors`/
 * `getUserVisits`, que selecionam só `id`, `data`, `created_at`,
 * `updated_at`).
 */
class DataSanitizer
{
    /**
     * Chaves do blob `data` que nunca devem sair numa response, mesmo que
     * ainda existam em linhas antigas. Hoje só `id-token` (ver acima) —
     * array em vez de uma constante única pra deixar espaço óbvio pra mais
     * chaves no futuro sem mudar a assinatura do método.
     */
    protected const SENSITIVE_KEYS = ['id-token'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
