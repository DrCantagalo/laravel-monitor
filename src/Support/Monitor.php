<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor as MonitorModel;
use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * Mesmo uso de `tag()`/`recognize()`: normalmente exige uma sessão de
     * monitor já ativa (`session('monitor_id')`) — é a request do próprio
     * visitante, ex. o momento de um cadastro bem-sucedido. Pra eventos
     * sem sessão (webhook de pagamento, job em fila), ver
     * `addTagForUser()`.
     *
     * laravel-monitor 331: quando chamado ANTES de `session('monitor_id')`
     * existir — a primeira request de um visitante novo, se o
     * controller/rota chama `addTag()`/`addTags()` antes da "lógica de
     * volta" de `MonitorMethod` criar/achar o Monitor desta sessão — a
     * tag não é mais descartada. Em vez disso é guardada em
     * `PendingCustomTags` (singleton scoped no container, NUNCA Cache —
     * seria global entre requests/usuários — nem sessão, que é justamente
     * o que ainda não existe aqui) e aplicada depois por
     * `SessionVisitorTracker`/`AnonymousVisitorTracker::
     * applyPendingCustomTags()` assim que o Monitor desta mesma request é
     * criado ou achado. Numa request onde o tracking de verdade não roda
     * (IP ignorado, `avoid-monitor`, `skipTracking()`, visitante
     * bloqueado, console/Artisan) nada consome essa lista — ela é
     * descartada junto com o container da request, sem erro nem log (ver
     * docblock de `PendingCustomTags`).
     *
     * Cada tag passa por `sanitizeCustomTags()` ([a-z0-9._-], minúsculas,
     * `MonitorLabel::MAX_TAG_LENGTH`, nunca a tag reservada `user` nem
     * qualquer outra reservada futura) e, quando já há sessão de monitor
     * ativa, por `MonitorLabel::normalizeTags()` (dedupe contra as tags já
     * existentes do Monitor + `MAX_TAGS`) — idempotente, tag já presente
     * não duplica. Igual a `maybeTagOrigin()`/`syncUserTag()`
     * (`SessionVisitorTracker`), uma linha nova fica só com `tags` (sem
     * `kind`/`classified_at`): o Monitor continua NÃO classificado.
     *
     * Nunca lança exceção — falha de banco/sessão vira log warning + `false`,
     * pro cadastro/fluxo do site nunca quebrar por causa do monitor.
     * Retorna `true` se sobrou alguma tag válida depois da sanitização
     * (mesmo sem sessão de monitor ainda — fica pendente), `false` se
     * nenhuma tag sobreviveu à sanitização (chamada vazia, não é erro).
     */
    public function addTags(array $tags): bool
    {
        $clean = $this->sanitizeCustomTags($tags);

        if (empty($clean)) {
            return false;
        }

        $monitorId = session('monitor_id');

        if (! $monitorId) {
            app(PendingCustomTags::class)->push($clean);

            return true;
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

            if (
                $value === MonitorLabel::TAG_USER
                || $value === MonitorLabel::TAG_FILTER_ANY
                || str_starts_with($value, MonitorLabel::TAG_FILTER_NONE)
            ) {
                Log::warning('[laravel-monitor] Monitor::addTag(s) descartou a tag reservada.', ['tag' => $tag]);

                continue;
            }

            $clean[] = $value;
        }

        return MonitorLabel::normalizeTags($clean);
    }

    /**
     * Mescla `$tags` (já sanitizadas por `sanitizeCustomTags()`) nas tags
     * existentes do `MonitorLabel` do Monitor `$monitorId`. Desde
     * laravel-monitor 331, só delega pra `MonitorLabel::mergeCustomTags()`
     * (lógica de merge/lock/transaction movida pra lá) — extraído pra ser
     * o MESMO caminho que `SessionVisitorTracker`/
     * `AnonymousVisitorTracker::applyPendingCustomTags()` usam ao aplicar
     * as tags que ficaram pendentes em `PendingCustomTags` (ver docblock
     * de `addTags()` acima), sem duplicar a lógica de merge em dois
     * lugares. Mantido aqui (em vez de inlinado nos call sites) só pra
     * não mudar a assinatura/visibilidade que `addTags()`/
     * `addTagForUser()` já chamam.
     *
     * @param  array<int, string>  $tags
     */
    protected function applyCustomTags(int $monitorId, array $tags): bool
    {
        $applied = MonitorLabel::mergeCustomTags($monitorId, $tags);

        // laravel-monitor 402: mesmo gap de `markAsHuman()` acima (ver
        // comentário lá) — `addTag()`/`addTags()`/`addTagForUser()` são
        // chamadas explícitas do host app (nunca automáticas por
        // request, ao contrário de `maybeTagOrigin()`/`syncUserTag()`
        // internos dos trackers, que SIM rodam a cada visitante novo e
        // de propósito NÃO invalidam isto — bumpar a versão a cada
        // visitante novo destruiria o cache de listagens pra todo
        // mundo), então o custo de invalidar aqui é desprezível.
        if ($applied) {
            ListingsCache::invalidate();
        }

        return $applied;
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

    /**
     * laravel-monitor 377: marca o Monitor da SESSÃO ATUAL como humano,
     * depois que o HOST APP já validou alguma prova fora do escopo deste
     * pacote (Turnstile, checkbox "não sou um robô", teste de scroll,
     * etc.) — produzir essa prova é responsabilidade exclusiva do
     * chamador; este método só GRAVA o resultado em `monitor_labels`,
     * nunca verifica nada por conta própria.
     *
     * ATENÇÃO — ver README "IP classification"/seção deste método pro
     * aviso completo: NUNCA exponha uma rota/endpoint que chame este
     * método direto a partir do navegador sem validar a prova no servidor
     * primeiro. Sem essa validação server-side, qualquer visitante
     * (inclusive um bot de verdade) poderia se autodeclarar humano com uma
     * chamada HTTP crua — este pacote deliberadamente não oferece essa
     * rota; a chamada precisa vir de dentro do controller/action do
     * próprio host app, DEPOIS de confirmar a prova.
     *
     * Grava `kind=human`, `classified_at=now()` e `source='verified'`
     * (sem `$method`) ou `'verified-{method}'` (`$method` normalizado via
     * `Str::slug()` - minúsculo, restrito a `[a-z0-9-]` - e truncado a 15
     * caracteres; se nada sobrar da normalização, cai no `'verified'`
     * plano, mesmo tratamento de "nada sobrou" que `sanitizeCustomTags()`
     * já dá a uma tag inválida).
     *
     * Precedência de `source` (`MonitorLabel::isProtectedSource()`, a
     * MESMA checagem que protege `MonitorController::setMonitorKind`
     * contra `source=ai`): `manual` > `auth` > `verified-*` > `ai`. Sobre
     * um label já `manual`/`auth`/qualquer `verified*`, esta chamada é um
     * NO-OP silencioso - não lança, não muda `kind`/`source` e,
     * CRUCIALMENTE, não toca `classified_at`: `classified_at` sempre fica
     * na data da PRIMEIRA prova aceita; uma segunda chamada com `$method`
     * diferente não "atualiza" nada, a primeira prova sempre vence. Sobre
     * `source=ai` (seja `kind=human` ou `kind=bot`) ou sem label nenhum
     * ainda, aplica normal.
     *
     * Um label existente só com `tags`/`note` (`kind=null`, ex: criado
     * antes por `addTag()`) NÃO conta como classificado, mesmo que a
     * coluna `source` já tenha o default `manual` do próprio banco (ver
     * `MonitorLabel::isProtectedSource()`) - por isso a checagem abaixo
     * exige `kind !== null` antes de olhar `source`, igual
     * `SessionVisitorTracker::maybeAutoHumanClassify()` já faz; esse caso
     * aplica normal, como "sem label nenhum ainda".
     *
     * `human_guest`/`human_user` continuam 100% derivados de
     * `data.user_id` (tag reservada `user`, ver "Authenticated user
     * tagging") - este método nunca toca `tags`, só `kind`/`source`/
     * `classified_at`.
     *
     * Mesmo contrato de `tag()`/`recognize()`: requer `session('monitor_id')`
     * já existir (não enfileira nada parecido com `PendingCustomTags` -
     * diferente de `addTag()`/`addTags()`, que podem rodar antes do
     * Monitor da request existir); sem sessão de monitor ativa ou sem o
     * Monitor correspondente, retorna `false` sem lançar.
     */
    public function markAsHuman(?string $method = null): bool
    {
        $monitorId = session('monitor_id');

        if (! $monitorId) {
            return false;
        }

        if (! MonitorModel::whereKey($monitorId)->exists()) {
            return false;
        }

        $source = $this->verifiedSource($method);

        return DB::transaction(function () use ($monitorId, $source) {
            $label = MonitorLabel::where('monitor_id', $monitorId)->lockForUpdate()->first();

            if ($label && $label->kind !== null && MonitorLabel::isProtectedSource($label->source)) {
                return false;
            }

            $label ??= new MonitorLabel(['monitor_id' => $monitorId]);
            $label->kind = 'human';
            $label->source = $source;
            $label->classified_at = now();
            $label->save();

            // laravel-monitor 402: faltava aqui — `setMonitorKind`/
            // `setMonitorTags` (MonitorController) já invalidam
            // `ListingsCache` nas suas escritas de kind/tags, mas
            // `markAsHuman()` (escrita interna, fora do controller — ver
            // docblock da classe) nunca chamava isso, então o dashboard
            // podia continuar mostrando um Monitor recém-verificado como
            // humano em `unclassified`/`flagged` por até
            // `listings_cache_ttl_minutes` depois da chamada. Achado e
            // corrigido durante a auditoria da task home-page 402 (ver
            // CHANGELOG).
            ListingsCache::invalidate();

            return true;
        });
    }

    /**
     * Normaliza `$method` pro valor de `source` gravado por `markAsHuman()`
     * - `null` vira `'verified'` puro; qualquer string passa por
     * `Str::slug()` (minúsculo, `[a-z0-9-]`) e é truncada a 15 caracteres
     * ANTES de receber o prefixo `verified-` (o limite é sobre o método
     * em si, não sobre a string final com prefixo). Se a normalização não
     * sobrar nada (ex: `$method` só com caracteres descartados pelo
     * slug), cai no `'verified'` plano em vez de produzir um
     * `'verified-'` com sufixo vazio.
     */
    protected function verifiedSource(?string $method): string
    {
        if ($method === null) {
            return 'verified';
        }

        $normalized = mb_substr(Str::slug($method), 0, 15);

        return $normalized === '' ? 'verified' : 'verified-'.$normalized;
    }
}
