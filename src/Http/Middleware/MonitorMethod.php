<?php

namespace Drcantagalo\LaravelMonitor\Http\Middleware;

use Closure;
use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Drcantagalo\LaravelMonitor\Models\MonitorPath;
use Drcantagalo\LaravelMonitor\Support\AnonymousVisitorTracker;
use Drcantagalo\LaravelMonitor\Support\ScraperBlocker;
use Drcantagalo\LaravelMonitor\Support\SessionVisitorTracker;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class MonitorMethod
{
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
                try {
                    (new ScraperBlocker)->registerOffense($ip, 'scraper-path');
                } catch (\Throwable $e) {
                    Log::warning('[laravel-monitor] falha ao registrar ofensa automática de honeypot — Erro original: '.$e->getMessage());
                }
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

    /**
     * `monitor.ignore_ips`: IPs exatos ou faixas CIDR (IPv4/IPv6, via
     * `IpUtils::checkIp`). Só uma comparação em memória — não consulta banco
     * nem cache, então custa praticamente nada por request.
     */
    protected function isIgnoredIp(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $ignored = array_values(array_filter(array_map(
            fn ($entry) => trim((string) $entry),
            (array) config('monitor.ignore_ips', [])
        )));

        return $ignored !== [] && IpUtils::checkIp($ip, $ignored);
    }

    /**
     * Confere se o path (sem host) foi flagado como scrapper (via
     * flagScraperPath), cacheado como `isBlocked()` acima.
     *
     * `strtolower()` antes de comparar: `monitor_paths.path` é sempre
     * gravado em minúsculo (`MonitorController::normalizePathInput()`),
     * mas o path de uma request ao vivo mantém a caixa exata que o
     * visitante mandou. Sem normalizar aqui, esta query dependeria da
     * collation do banco pra casar (MySQL é case-insensitive por padrão,
     * SQLite/Postgres não são) - explícito em vez de implícito, e a chave
     * de cache também fica normalizada, senão "File.php" e "file.php"
     * geram entradas de cache separadas pro mesmo bloqueio.
     */
    protected function isPathBlocked(string $path): bool
    {
        $path = strtolower($path);
        $query = fn () => MonitorPath::where('path', $path)->where('status', 'trap')->exists();

        try {
            return Cache::remember(
                "monitor:blocked-path:{$path}",
                (int) config('monitor.blocked_ip_cache_ttl', 60),
                $query
            );
        } catch (QueryException $e) {
            // Tabela ainda não migrada: deixa subir pro catch(QueryException)
            // de handle(), que já trata esse caso assumindo `false`.
            throw $e;
        } catch (\Throwable $e) {
            // Cache store fora do ar (Redis/Memcached indisponível, etc):
            // NÃO assume `false` aqui — deixaria passar um path que devia
            // continuar bloqueado bem na janela de instabilidade. Em vez
            // disso, consulta o banco direto, sem cache, só logando o aviso.
            Log::warning('[laravel-monitor] cache store indisponível ao checar bloqueio de path — consultando banco diretamente. Erro original: '.$e->getMessage());

            return $query();
        }
    }

    /**
     * Confere se o IP está na blocklist (`monitor_blocked_ips`), cacheado
     * por `monitor.blocked_ip_cache_ttl` segundos pra evitar uma query por
     * request. Cache é invalidado em `updateBlockedIps` ao adicionar um
     * IP novo.
     *
     * `blocked_until` null = permanente (bloqueio manual, ou automático já
     * escalado a permanente — ver `ScraperBlocker::registerOffense`);
     * um `blocked_until` no passado não conta mais como bloqueado (mesmo
     * predicado de `BlockedIp::scopeActive()`, task 147 — reusado daqui em
     * vez de duplicado, pra nunca divergir do que `DataPruner` considera
     * "bloqueado agora"). Como o TTL do cache acima já é curto por padrão
     * (60s), a expiração natural do cache garante que um bloqueio expirado
     * some da aplicação nesse intervalo, sem precisar de nenhum job/cron
     * dedicado.
     */
    protected function isBlocked(string $ip): bool
    {
        $query = fn () => BlockedIp::where('ip', $ip)->active()->exists();

        try {
            return Cache::remember(
                "monitor:blocked-ip:{$ip}",
                (int) config('monitor.blocked_ip_cache_ttl', 60),
                $query
            );
        } catch (QueryException $e) {
            // Tabela ainda não migrada: deixa subir pro catch(QueryException)
            // de handle(), que já trata esse caso assumindo `false`.
            throw $e;
        } catch (\Throwable $e) {
            // Cache store fora do ar (Redis/Memcached indisponível, etc):
            // NÃO assume `false` aqui — deixaria passar um IP que devia
            // continuar bloqueado bem na janela de instabilidade. Em vez
            // disso, consulta o banco direto, sem cache, só logando o aviso.
            Log::warning('[laravel-monitor] cache store indisponível ao checar bloqueio de IP — consultando banco diretamente. Erro original: '.$e->getMessage());

            return $query();
        }
    }

    /**
     * Incrementa o contador de tentativas bloqueadas desse IP em
     * `monitor_block_results` (task 83) — chamado logo antes do
     * `abort()` acima, cobrindo os dois motivos de bloqueio de uma vez
     * só: `$blocked` já é o OR de `isBlocked()`/`isPathBlocked()`, então
     * não importa qual dos dois disparou — o IP da request atual é quem
     * toma o abort (403 se já bloqueado, 404 se é o primeiro hit num path
     * honeypot — task 148) e é quem conta aqui, inclusive um IP nunca
     * antes visto batendo num path já flagado como honeypot (nunca esteve
     * em `monitor_blocked_ips` por si só). O status HTTP da resposta não
     * afeta essa contagem — ela soma qualquer tentativa bloqueada,
     * independente do código retornado.
     *
     * Upsert atômico via query builder (uma query, sem race entre um
     * SELECT+UPDATE/INSERT concorrentes do mesmo IP martelando o mesmo
     * endpoint bloqueado) — `upsert()` gera o SQL correto pro driver ativo
     * (`ON DUPLICATE KEY UPDATE` no MySQL, `ON CONFLICT` no SQLite/Postgres),
     * mesmo motivo de escolha já documentado pros outros upserts do
     * pacote (`IpStat::recordVisit`).
     *
     * Fail-open: se `monitor_block_results` ainda não existir (migration
     * não rodou), essa query não pode derrubar o bloqueio em si —
     * `abort()` roda de qualquer jeito, fora deste método, mesmo se o
     * catch abaixo disparar.
     */
    protected function recordBlockedAttempt(string $ip): void
    {
        try {
            DB::table('monitor_block_results')->upsert(
                ['ip' => $ip, 'counter' => 1, 'last_attempt_at' => now()],
                ['ip'],
                ['counter' => DB::raw('counter + 1'), 'last_attempt_at' => now()]
            );
        } catch (QueryException $e) {
            Log::warning('[laravel-monitor] tabela monitor_block_results não encontrada — rode `php artisan migrate` ou `php artisan monitor:install`. Erro original: '.$e->getMessage());
        }
    }
}
