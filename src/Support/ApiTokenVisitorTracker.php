<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor as MonitorModel;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * laravel-monitor 400: grouping do grupo `api` (`MonitorApiMethod`) por
 * token Sanctum — UM Monitor por `personal_access_tokens.id` (NUNCA por
 * usuário: dois tokens do mesmo usuário são dois Monitors, ex. um app
 * mobile e um script de integração do mesmo cliente), com `data.user_id`
 * = dono do token. `$token` recebido aqui é sempre um
 * `Laravel\Sanctum\PersonalAccessToken` já resolvido/validado por
 * `MonitorApiMethod` (via `PersonalAccessToken::findToken()`) — tipado
 * como `object` (não a classe concreta) de propósito: esta classe nunca
 * `use`s nem referencia `Laravel\Sanctum\*` em código, então continua
 * carregável/instanciável mesmo com `laravel/sanctum` ausente (ela só
 * nunca é efetivamente CHAMADA nesse caso — quem decide isso é
 * `MonitorApiMethod::sanctumAvailable()`).
 */
class ApiTokenVisitorTracker
{
    /**
     * Resolve (ou cria) o Monitor deste token e registra o hit. Classifica
     * `kind=bot,source=auth,tags=[user,api]` SÓ na criação — nunca
     * reclassifica num Monitor de token já existente (mesmo espírito de
     * "todo Monitor começa indefinido, nunca reclassificado automaticamente
     * depois" do resto do pacote, só que aqui já nasce classificado).
     */
    public function track(object $token, string $path, bool $notFound = false): MonitorModel
    {
        $tokenId = (int) $token->getKey();
        $monitor = MonitorModel::where('token_id', $tokenId)->first();

        if (! $monitor) {
            try {
                $monitor = MonitorModel::create([
                    'data' => ['user_id' => $this->ownerId($token)],
                    'token_id' => $tokenId,
                ]);
                $this->classify($monitor->id);
            } catch (UniqueConstraintViolationException) {
                // Corrida: outra request concorrente com o MESMO token
                // criou o Monitor entre o SELECT e o INSERT acima — busca
                // de novo em vez de duplicar (unique em `token_id` já
                // impediu a duplicata em si).
                $monitor = MonitorModel::where('token_id', $tokenId)->first();
            }
        }

        if ($monitor) {
            $monitor->recordHit($path, $notFound);
            $monitor->touch();
        }

        return $monitor;
    }

    /**
     * `tokenable_id` é a coluna crua de `personal_access_tokens`
     * (relação polimórfica tokenable_type/tokenable_id) — lida direto do
     * atributo, sem resolver a relação `tokenable()` (evitaria uma query
     * extra por request só pra pegar o id, que já está na própria linha).
     */
    protected function ownerId(object $token): mixed
    {
        return $token->tokenable_id ?? null;
    }

    /**
     * laravel-monitor 400, item 4: kind=bot/source=auth (protegido contra
     * `source=ai` por `MonitorLabel::isProtectedSource()`, que já cobre
     * `auth` — ver docblock lá) + tags reservada `user` (mesmo significado
     * de sempre: "ligado a um user_id") + `api` (nova, distingue um
     * Monitor de token de um Monitor humano autenticado via sessão).
     * `firstOrNew` (não deveria nunca achar nada — Monitor acabou de
     * nascer — mas segue o padrão defensivo do resto do pacote) +
     * `UniqueConstraintViolationException` ignorada (mesma corrida rara e
     * inofensiva de `SessionVisitorTracker::maybeTagOrigin()`).
     */
    protected function classify(int $monitorId): void
    {
        $label = MonitorLabel::firstOrNew(['monitor_id' => $monitorId]);
        $label->kind = 'bot';
        $label->source = 'auth';
        $label->classified_at = now();
        $label->tags = MonitorLabel::normalizeTags([
            ...($label->tags ?? []),
            MonitorLabel::TAG_USER,
            'api',
        ]);

        try {
            $label->save();
        } catch (UniqueConstraintViolationException) {
            return;
        }

        // Sem isso, `getMonitorQueue(group=clean_bots)` e qualquer
        // listagem filtrada por tag/kind continuariam sem este Monitor até
        // `listings_cache_ttl_minutes` expirar por conta própria — mesmo
        // mecanismo usado por `setMonitorKind`/`setMonitorTags`.
        ListingsCache::invalidate();
    }
}
