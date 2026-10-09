<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor as MonitorModel;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class Monitor
{
    /**
     * Chaves internas do Monitor que `tag()` nunca pode sobrescrever, mesmo
     * que o host app mande um par com esse nome. Vivia como
     * `MonitorController::PROTECTED_DATA_KEYS` até v0.2.0 — movida pra cá
     * (constante pública) porque o controller foi removido junto com as
     * rotas públicas de visitante (ver CHANGELOG v0.2.0).
     */
    public const PROTECTED_DATA_KEYS = [
        'sessions', 'ips', 'visits', 'page', 'id-token', 'ua', 'user_id',
    ];

    /**
     * Marca a request atual como "não rastrear": `MonitorMethod` vai
     * ignorar essa passagem pela sessão (não conta como visita nem
     * reescreve `data` do Monitor). A flag é lida e apagada por
     * `SessionVisitorTracker::track()` na próxima vez que o middleware
     * rodar a "lógica de volta" para esta sessão - chame antes de
     * responder a request que não deve ser rastreada (ex: endpoints
     * AJAX/API internos dentro do grupo `web`).
     */
    public function skipTracking(): void
    {
        session()->put(config('monitor.skip_session_key', 'avoid_monitor'), true);
    }

    /**
     * Equivalente server-side do antigo `GET /monitor/update-data`
     * (removido em v0.2.0 — ver CHANGELOG). Grava pares chave/valor
     * arbitrários em `data` do Monitor da sessão atual — base de
     * segmentação/tags (idioma, preferências etc.), não é CRM/lead ainda.
     * Chaves internas usadas pelo MonitorMethod (PROTECTED_DATA_KEYS) são
     * ignoradas silenciosamente para não corromper o tracking.
     *
     * Mesmo contrato de antes, adaptado pra chamada direta (sem HTTP): não
     * lança exceção quando não há sessão de monitor ativa ou payload vazio
     * — só retorna `false`, e o caller confere o bool em vez de parsear um
     * erro JSON.
     */
    public function tag(array $data): bool
    {
        $monitorId = session('monitor_id');

        if (! $monitorId) {
            return false;
        }

        $user = MonitorModel::find($monitorId);

        if (! $user) {
            return false;
        }

        if (empty($data)) {
            return false;
        }

        $current = $user->data;

        foreach ($data as $key => $value) {
            if (in_array($key, self::PROTECTED_DATA_KEYS, true)) {
                continue;
            }

            $current[$key] = $value;
        }

        $user->data = $current;
        $user->save();

        return true;
    }

    /**
     * laravel-monitor 320 (v0.66.0): API pública pro host app marcar
     * conversões (cadastro, venda, qualquer evento que o dono queira
     * observar) como tag custom no `MonitorLabel` do visitante — grava em
     * `monitor_labels.tags`, nada a ver com `tag()`/`data` acima (ver
     * README "Custom conversion tags" pra não confundir os dois).
     *
     * Atalho de `addTags()` com um único valor — ver docblock lá pro
     * contrato completo (sessão exigida, sanitização, nunca lança).
     */
    public function addTag(string $tag): bool
    {
        return $this->addTags([$tag]);
    }

    /**
     * Mesmo uso de `tag()`/`recognize()`: exige uma sessão de monitor já
     * ativa (`session('monitor_id')`) — é a request do próprio visitante,
     * ex. o momento de um cadastro bem-sucedido. Pra eventos sem sessão
     * (webhook de pagamento, job em fila), ver `addTagForUser()`.
     *
     * Cada tag passa por `sanitizeCustomTags()` ([a-z0-9._-], minúsculas,
     * `MonitorLabel::MAX_TAG_LENGTH`, nunca a tag reservada `user` nem
     * qualquer outra reservada futura) e depois por
     * `MonitorLabel::normalizeTags()` (dedupe contra as tags já existentes
     * do Monitor + `MAX_TAGS`) — idempotente, tag já presente não duplica.
     * Igual a `maybeTagOrigin()`/`syncUserTag()` (`SessionVisitorTracker`),
     * uma linha nova fica só com `tags` (sem `kind`/`classified_at`): o
     * Monitor continua NÃO classificado.
     *
     * Nunca lança exceção — falha de banco/sessão vira log warning + `false`,
     * pro cadastro/fluxo do site nunca quebrar por causa do monitor.
     * Retorna `false` sem nenhum log quando não há sessão de monitor ou
     * nenhuma tag sobrou depois da sanitização (chamada vazia, não é erro).
     */
    public function addTags(array $tags): bool
    {
        $monitorId = session('monitor_id');

        if (! $monitorId) {
            return false;
        }

        $clean = $this->sanitizeCustomTags($tags);

        if (empty($clean)) {
            return false;
        }

        try {
            return $this->applyCustomTags((int) $monitorId, $clean);
        } catch (Throwable $e) {
            Log::warning('[laravel-monitor] Monitor::addTags falhou. Erro original: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Mesma tag custom, mas pra eventos SEM sessão do visitante (webhook
     * de pagamento, job em fila) — aplica em TODOS os `Monitor`s ligados a
     * esse usuário (mesmo vínculo `data.user_id` que sustenta a tag
     * reservada `user`, via `Monitor::scopeForUserId()`). Aceita o próprio
     * model autenticável (usa `getKey()`) ou já o id (int/string numérica).
     *
     * Retorna quantos Monitors foram tagueados (0 se nenhum — usuário sem
     * `user_id` ligado a nenhum Monitor, ou nenhuma tag válida sobrou da
     * sanitização). Idempotente por Monitor, mesmo contrato de `addTags()`
     * (dedupe, `MAX_TAGS`, nunca a reservada `user`, nunca classifica,
     * nunca lança — chamadores concorrentes pra mesma compra, ex.
     * `captureOrder()` E o webhook, podem rodar sem duplicar a tag).
     */
    public function addTagForUser(mixed $user, string|array $tags): int
    {
        $userId = is_object($user) && method_exists($user, 'getKey') ? $user->getKey() : $user;

        if (! is_numeric($userId)) {
            Log::warning('[laravel-monitor] Monitor::addTagForUser chamado com user inválido.');

            return 0;
        }

        $clean = $this->sanitizeCustomTags(is_array($tags) ? $tags : [$tags]);

        if (empty($clean)) {
            return 0;
        }

        try {
            $monitorIds = MonitorModel::forUserId((int) $userId)->pluck('id');
        } catch (Throwable $e) {
            Log::warning('[laravel-monitor] Monitor::addTagForUser falhou ao buscar os Monitors do usuário. Erro original: '.$e->getMessage());

            return 0;
        }

        $count = 0;

        // Cada Monitor é tagueado na sua própria transaction (`applyCustomTags()`):
        // uma falha isolada (ex: corrida rara com `UniqueConstraintViolationException`
        // num Monitor específico) não pode derrubar a contagem dos demais já
        // tagueados com sucesso nesta mesma chamada.
        foreach ($monitorIds as $monitorId) {
            try {
                if ($this->applyCustomTags((int) $monitorId, $clean)) {
                    $count++;
                }
            } catch (Throwable $e) {
                Log::warning("[laravel-monitor] Monitor::addTagForUser falhou pro monitor_id {$monitorId}. Erro original: ".$e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Sanitização própria das tags custom, aplicada ANTES de
     * `MonitorLabel::normalizeTags()` — mesmo padrão de
     * `VisitorOriginDetector::sanitize()` (restringe a `[a-z0-9._-]`, mais
     * estrito que `normalizeTags()`), porque o valor aqui vem direto do
     * código do host app (string literal tipo `'registered'`/`'buyer'`),
     * não de input HTTP, mas ainda assim não documentado/controlado por
     * este pacote. Valor inválido (vazio depois da limpeza, longo demais,
     * ou a tag reservada `user`) é descartado com log, nunca um erro.
     *
     * @param  array<int, mixed>  $tags
     * @return array<int, string>
     */
    protected function sanitizeCustomTags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $value = mb_strtolower(trim($tag));
            $value = preg_replace('/[^a-z0-9._-]/', '', $value) ?? '';

            if ($value === '' || mb_strlen($value) > MonitorLabel::MAX_TAG_LENGTH) {
                Log::warning('[laravel-monitor] Monitor::addTag(s) descartou uma tag inválida.', ['tag' => $tag]);

                continue;
            }

            if (in_array($value, [MonitorLabel::TAG_USER], true)) {
                Log::warning('[laravel-monitor] Monitor::addTag(s) descartou a tag reservada.', ['tag' => $tag]);

                continue;
            }

            $clean[] = $value;
        }

        return MonitorLabel::normalizeTags($clean);
    }

    /**
     * Mescla `$tags` (já sanitizadas por `sanitizeCustomTags()`) nas tags
     * existentes do `MonitorLabel` do Monitor `$monitorId` — `firstOrNew`
     * + `lockForUpdate()` dentro de uma transaction, mesmo padrão de
     * concorrência de `MonitorController::setMonitorTags()` (aqui
     * relevante de verdade: `addTagForUser()` pode rodar duas vezes pra
     * mesma compra, `captureOrder()` e o webhook). `MAX_TAGS` pode
     * descartar alguma tag do pedido (merge com as já existentes) — log,
     * nunca erro. Nunca toca `kind`/`classified_at`: uma linha nova fica
     * NÃO classificada.
     *
     * @param  array<int, string>  $tags
     */
    protected function applyCustomTags(int $monitorId, array $tags): bool
    {
        return DB::transaction(function () use ($monitorId, $tags) {
            $label = MonitorLabel::where('monitor_id', $monitorId)->lockForUpdate()->first()
                ?? new MonitorLabel(['monitor_id' => $monitorId, 'tags' => []]);

            $existing = $label->tags ?? [];
            $merged = MonitorLabel::normalizeTags([...$existing, ...$tags]);
            $dropped = array_diff($tags, $merged);

            if (! empty($dropped)) {
                Log::warning('[laravel-monitor] Monitor::addTag(s) descartou tag(s) por exceder MAX_TAGS.', [
                    'monitor_id' => $monitorId,
                    'dropped' => array_values($dropped),
                ]);
            }

            $label->tags = $merged;
            $label->saveOrPrune();

            return true;
        });
    }

    /**
     * Equivalente server-side do antigo `GET /monitor/remember-me`
     * (removido em v0.2.0 — ver CHANGELOG). Lê o cookie de remember-me da
     * request atual (`request()->cookie(...)`, sem round-trip HTTP) e
     * busca o Monitor correspondente.
     *
     * Diferente do endpoint antigo (que sempre respondia `success: true`
     * bastando o cookie existir, mesmo sem bater com nenhuma linha —
     * quem de fato validava era `SessionVisitorTracker::track()` mais
     * adiante, silenciosamente), aqui o lookup roda na hora e o retorno
     * reflete se o visitante foi realmente reconhecido. Quando encontrado,
     * seta `session(['remember_me' => $token])` — mesmo sinal que o
     * middleware `MonitorMethod` já consumia antes na "lógica de volta"
     * desta mesma request (`SessionVisitorTracker::track()` refaz esse
     * lookup ao consumir a flag; redundante mas inofensivo), então o
     * comportamento de reconexão do visitante continua idêntico ao
     * anterior.
     */
    public function recognize(): bool
    {
        $token = request()->cookie(config('monitor.remember_cookie', 'monitor_id_token'));

        if (! $token) {
            return false;
        }

        $user = MonitorModel::where('id_token', $token)->first();

        if (! $user) {
            return false;
        }

        session(['remember_me' => $token]);

        return true;
    }
}
