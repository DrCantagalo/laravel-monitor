<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\MonitorLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * laravel-monitor 319: detecta de onde um visitante veio (first-touch)
 * pra gravar como uma tag custom normal no Monitor recém-criado — NUNCA
 * como classificação. Único consumidor: `SessionVisitorTracker`/
 * `AnonymousVisitorTracker`, só no ramo de criação de um Monitor NOVO
 * (ver `maybeTagOrigin()` nos dois) — visitas seguintes do mesmo Monitor
 * nunca chamam `detect()` de novo, e Monitors já existentes antes desta
 * feature não são retroativamente tagueados.
 *
 * Ordem de detecção, primeira que responder vence; nenhuma → sem tag
 * (`null`):
 * 1. `utm_source` (ou o alias curto `lm`) na query string de entrada.
 * 2. Click ID conhecido (`config('monitor.origin_click_ids')`) → fonte
 *    fixa.
 * 3. Domínio do `Referer`: mapeado
 *    (`config('monitor.origin_referer_domains')`, com wildcard tipo
 *    "google.*") → fonte fixa; domínio do PRÓPRIO site → ignorado (não
 *    é origem); qualquer outro domínio → o domínio registrável cru (ver
 *    `registrableDomain()`).
 */
class VisitorOriginDetector
{
    public function detect(Request $request): ?string
    {
        if (! config('monitor.origin_tagging', true)) {
            return null;
        }

        return $this->detectFromUtm($request)
            ?? $this->detectFromClickId($request)
            ?? $this->detectFromReferer($request);
    }

    /**
     * `lm` é um alias curto do próprio usuário (não é padrão de
     * mercado) — aceito só como fallback quando `utm_source` não vier,
     * nunca combinado/mesclado com ele.
     */
    protected function detectFromUtm(Request $request): ?string
    {
        $value = $request->query('utm_source') ?? $request->query('lm');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->sanitize($value);
    }

    protected function detectFromClickId(Request $request): ?string
    {
        foreach ((array) config('monitor.origin_click_ids', []) as $param => $source) {
            if (is_string($param) && $request->query($param) !== null) {
                return $this->sanitize((string) $source);
            }
        }

        return null;
    }

    protected function detectFromReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! $referer) {
            return null;
        }

        $host = $this->stripWww(strtolower((string) (parse_url($referer, PHP_URL_HOST) ?? '')));

        if ($host === '') {
            return null;
        }

        // Referer do próprio site não é origem — nunca gera tag.
        $ownHost = $this->stripWww(strtolower((string) $request->getHost()));

        if ($host === $ownHost) {
            return null;
        }

        foreach ((array) config('monitor.origin_referer_domains', []) as $pattern => $source) {
            if (is_string($pattern) && Str::is(strtolower($pattern), $host)) {
                return $this->sanitize((string) $source);
            }
        }

        return $this->sanitize($this->registrableDomain($host));
    }

    protected function stripWww(string $host): string
    {
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Heurística simples, SEM lista de sufixos públicos (PSL): últimos
     * 2 labels do host. Correta pro caso comum ("blog.example.com" ->
     * "example.com"), mas produz só o sufixo composto ("co.uk"/"com.br")
     * em vez do domínio completo pra TLDs assim — limitação conhecida,
     * documentada no README, aceitável porque o pior caso ainda é uma
     * tag informativa, nunca um erro/exceção.
     */
    protected function registrableDomain(string $host): string
    {
        $labels = explode('.', $host);

        return implode('.', array_slice($labels, -2));
    }

    /**
     * Sanitização da tag de origem: o valor de `utm_source`/`lm` é
     * texto livre controlado pelo visitante, e o `Referer` também pode
     * ser forjado numa request manual — restringe a `[a-z0-9._-]`
     * (mais estrito que `MonitorLabel::normalizeTags()`, aplicado
     * depois pelo chamador) e NUNCA deixa passar a tag reservada
     * `MonitorLabel::TAG_USER` (nem qualquer outra reservada futura).
     * Resultado vazio depois da limpeza (ou reservado) -> `null`, que
     * faz `detect()` cair pro próximo método da ordem.
     */
    protected function sanitize(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]/', '', $value) ?? '';

        if ($value === '' || mb_strlen($value) > MonitorLabel::MAX_TAG_LENGTH) {
            return null;
        }

        if (in_array($value, [MonitorLabel::TAG_USER], true)) {
            return null;
        }

        return $value;
    }
}
