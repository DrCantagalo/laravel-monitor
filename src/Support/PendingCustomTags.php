<?php

namespace Drcantagalo\LaravelMonitor\Support;

/**
 * laravel-monitor 331: guarda, para a request atual, as tags custom que
 * `Monitor::addTags()` recebeu ANTES de existir `session('monitor_id')`
 * — a primeira request de um visitante novo, quando o controller/rota
 * chama `Monitor::addTag()`/`addTags()` mas o `Monitor` em si só é
 * criado/achado na "lógica de volta" de `MonitorMethod` (ver docblock de
 * `Support\Monitor::addTags()`).
 *
 * Bind obrigatoriamente `scoped` (ou binding por instância) no container
 * — NUNCA `Cache` (global entre requests/usuários, a tag de uma pessoa
 * vazaria pra outra) nem sessão (ainda não existe pra esse visitante
 * nesse ponto, que é exatamente o problema que isto resolve). "Scoped"
 * já basta: cada ciclo request/resposta tradicional (PHP-FPM, `artisan
 * serve`) roda num container novo por si só, então esta lista nunca
 * sobrevive além da request que a criou - só é relevante de verdade sob
 * Octane/workers de fila de vida longa, onde o próprio framework
 * recicla bindings `scoped` entre execuções.
 *
 * Consumida por `SessionVisitorTracker`/`AnonymousVisitorTracker`
 * (`applyPendingCustomTags()`) assim que o Monitor da request é
 * criado/achado - mesmo caminho de merge que `Monitor::applyCustomTags()`
 * já usa (`MonitorLabel::mergeCustomTags()`: dedupe, `MAX_TAGS`, nunca a
 * tag reservada `user`, nunca classifica).
 *
 * Em qualquer request onde o tracking não roda de verdade — IP em
 * `monitor.ignore_ips`, rota com o middleware `avoid-monitor`,
 * `Monitor::skipTracking()` chamado explicitamente, visitante bloqueado,
 * ou execução via console/Artisan (`MonitorMethod` nunca roda) — nada
 * consome esta lista: ela simplesmente é descartada junto com o
 * container da request, sem lançar exceção e sem logar como falha. Não
 * há nada "errado" nesse caminho — a tag só não tinha pra onde ir.
 */
class PendingCustomTags
{
    /** @var array<int, string> */
    protected array $tags = [];

    /**
     * Acrescenta tags (já sanitizadas pelo chamador, ver
     * `Monitor::sanitizeCustomTags()`) à lista pendente desta request.
     * Mais de uma chamada na mesma request simplesmente acumula - o
     * dedupe/`MAX_TAGS` de verdade só acontece quando a lista for
     * aplicada (`MonitorLabel::mergeCustomTags()`), igual ao
     * comportamento já existente pra tags aplicadas direto.
     *
     * @param  array<int, string>  $tags
     */
    public function push(array $tags): void
    {
        $this->tags = [...$this->tags, ...$tags];
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->tags;
    }

    public function isEmpty(): bool
    {
        return empty($this->tags);
    }

    /**
     * Esvazia a lista - chamado por `applyPendingCustomTags()` depois de
     * aplicar (ou mesmo quando não havia nada a aplicar), pra nunca
     * reaplicar a mesma tag numa segunda chamada de `track()` dentro da
     * mesma request (não deveria acontecer hoje, mas evita duplicar se
     * algum dia acontecer).
     */
    public function flush(): void
    {
        $this->tags = [];
    }
}
