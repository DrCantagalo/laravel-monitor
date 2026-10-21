<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Drcantagalo\LaravelMonitor\Http\Middleware\Concerns\BlocksInboundRequests;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MonitorMethod
{
    // laravel-monitor 400: checagem de ignored-IPs + enforcement de
    // IP/path bloqueado ("na ida") agora vive em `BlocksInboundRequests`,
    // compartilhada com `MonitorApiMethod` (grupo `api`) — ver docblock do
    // trait pro raciocínio completo de por que isso saiu daqui.
    use BlocksInboundRequests;

    public function __construct(
        protected SessionVisitorTracker $sessionTracker,
        protected AnonymousVisitorTracker $anonymousTracker,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. LÓGICA DE "IDA"
        if (app()->runningInConsole()) {
            return $next($request);
        }

        // Capturamos dados básicos antes do processamento
        //
        // Prefixamos o path com o host: sites multidominio/multisubdominio
        // que compartilham a mesma instalação do pacote perdiam essa
        // informação (ex: "/dashboard/3/blacklist" não dizia se veio de
        // "app.exemplo.com" ou "admin.exemplo.com").
        $pathOnly = ltrim($request->path(), '/');
        $path = $request->getHost().'/'.$pathOnly;
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');

        // IP configurado em `monitor.ignore_ips` (o próprio servidor, IPs de
        // monitoramento): passa direto, antes de qualquer consulta ao banco
        // — sem tracking, sem sinal de scraper, sem checagem de bloqueio.
        if ($this->isIgnoredIp($ip)) {
            return $next($request);
        }

        // IP bloqueado (via updateBlockedIps) ou path flagado como scrapper
        // (via flagScraperPath): corta o request aqui, antes de qualquer
        // tracking/detecção. Checado antes de tudo (inclusive requests com
        // sessão) — bloqueio vale pra qualquer origem. Path é checado sem o
        // host: uma installation pode atender vários subdomínios (ver
        // comentário acima sobre o prefixo de host em `data.page`), e o
        // flag deve proteger todos eles.
        //
        // Fail-open: se as tabelas ainda não existirem (composer require
        // feito mas migrations ainda não rodaram), essa query não pode
        // derrubar o site inteiro do cliente. abort() fica FORA do try pra
        // não ser engolido pelo catch.
        //
        // As duas checagens ficam separadas (em vez de um único OR) porque
        // precisamos saber qual delas disparou: um IP que bate num path
        // honeypot precisa entrar em `monitor_blocked_ips` (task 146) — mas
        // só na primeira vez, antes de já estar bloqueado por IP. O `&&`
        // com `! $ipBlocked` preserva o short-circuit que o `||` original já
        // dava (IP já bloqueado nunca chega a consultar `isPathBlocked`),
        // então esse ramo continua sem custo extra no caminho quente comum.
        // A mesma separação também decide o status HTTP da resposta (task
        // 148, ver abaixo).
        try {
            $ipBlocked = $this->isBlocked($ip);
            $pathBlocked = ! $ipBlocked && $this->isPathBlocked($pathOnly);
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_blocked_ips ou monitor_paths não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
            $ipBlocked = false;
            $pathBlocked = false;
        }

        if ($ipBlocked || $pathBlocked) {
            if ($pathBlocked) {
                // Honeypot = sinal de maior confiança (README "Honeypot
                // hits"): um hit já basta pra registrar a ofensa e entrar
                // pra `monitor_blocked_ips`, mesma escada temporária/
                // escalonada do resto do auto-block. `$pathBlocked` só é
                // true quando `$ipBlocked` ainda é false, então isso roda
                // no máximo uma vez por ciclo de bloqueio — a partir do 2º
                // hit `isBlocked($ip)` já responde antes de chegar aqui, sem
                // incrementar `strike_count`/`lifetime_offense_count` de
                // novo (ver ScraperBlocker::registerOffense). Try/catch:
                // falha ao registrar (banco fora, tabela ausente) não pode
                // impedir o abort() abaixo nem virar 500 — mesmo fail-open
                // do resto do método.
                $this->registerHoneypotOffense($ip);
            }

            $this->recordBlockedAttempt($ip);

            // Task 148: um scanner batendo num path honeypot via
            // $pathBlocked (IP ainda não em monitor_blocked_ips, primeiro
            // hit) recebe 404, indistinguível de uma rota inexistente
            // qualquer — o 403 denunciava a armadilha (403 só no path
            // monitorado, 404 em todo o resto é um oráculo de scan trivial
            // de se detectar). A ofensa acima e recordBlockedAttempt já
            // rodaram de qualquer forma, então o IP entra em
            // monitor_blocked_ips e o contador de tentativas bloqueadas
            // soma normalmente — só o status HTTP da resposta muda. A
            // partir do 2º hit do mesmo IP (ou qualquer hit de um IP já
            // bloqueado por outro motivo) $ipBlocked é true e a resposta
            // volta a ser 403: preserva o diagnóstico de falso positivo
            // (CGNAT) nos logs, sem mudar o comportamento pra quem já está
            // bloqueado.
            abort($pathBlocked ? 404 : 403);
        }

        // 2. PROCESSAMENTO (O Laravel segue para os outros middlewares e para o Controller)
        $response = $next($request);

        // 3. LÓGICA DE "VOLTA" (Agora a Session já está disponível!)
        try {
            $notFound = $response->getStatusCode() === 404;

            if ($request->hasSession()) {
                $this->sessionTracker->track($request, $response, $path, $userAgent, $ip, $notFound);
            } else {
                $this->anonymousTracker->track($request, $path, $userAgent, $ip, $notFound);
            }
        } catch (Exception $e) {
            Log::error('Monitor Package Error: '.$e->getMessage());
        }

        return $response;
    }

    // laravel-monitor 400: isIgnoredIp()/isPathBlocked()/isBlocked()/
    // recordBlockedAttempt()/registerHoneypotOffense() agora vivem em
    // `Concerns\BlocksInboundRequests` (importado acima) — ver o docblock
    // do trait pro raciocínio completo.
}
