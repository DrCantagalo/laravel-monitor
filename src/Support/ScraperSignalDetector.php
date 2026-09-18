<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ScraperSignalDetector
{
    /**
     * Heurística de detecção de scraper/bot, compartilhada entre
     * `AnonymousVisitorTracker` (requests sem sessão) e
     * `SessionVisitorTracker` (requests com sessão). Combina sinais já
     * disponíveis no request; cada sinal disparado entra na lista
     * retornada, e quem decide o que fazer com isso (marcar, bloquear,
     * etc.) é o chamador — aqui só detectamos.
     *
     * `$visitCount` é o total acumulado de visitas desse IP incluindo a
     * request atual (`IpStat::visitCount($ip) + 1`, calculado pelo
     * chamador antes de `IpStat::recordVisit()` gravar o incremento -
     * ver laravel-monitor 143) - default `0` só pra manter chamadas
     * antigas/testes que não passam esse argumento funcionando sem
     * disparar o sinal novo indevidamente.
     *
     * @return string[] lista dos sinais disparados
     */
    public function detect(Request $request, string $ip, ?string $userAgent, int $visitCount = 0): array
    {
        $signals = [];

        // Sinal 1: alta frequência de requests do mesmo IP numa janela curta.
        $window = (int) config('monitor.scraper_frequency_window_seconds', 10);
        $threshold = (int) config('monitor.scraper_frequency_threshold', 5);
        $cacheKey = "monitor:scraper-freq:{$ip}";
        $count = (int) Cache::get($cacheKey, 0) + 1;
        Cache::put($cacheKey, $count, $window);

        if ($count > $threshold) {
            $signals[] = 'high_frequency';
        }

        // Sinal 2: user-agent vazio ou de bot conhecido.
        if (empty($userAgent)) {
            $signals[] = 'empty_user_agent';
        } else {
            $knownBots = config('monitor.scraper_known_bot_user_agents', []);
            foreach ($knownBots as $needle) {
                if (stripos($userAgent, $needle) !== false) {
                    $signals[] = 'known_bot_user_agent';
                    break;
                }
            }
        }

        // Sinal 3: ausência de headers comuns de browser.
        $expectedHeaders = ['Accept', 'Accept-Language', 'Accept-Encoding'];
        $missing = 0;
        foreach ($expectedHeaders as $header) {
            if (! $request->hasHeader($header)) {
                $missing++;
            }
        }

        if ($missing >= 2) {
            $signals[] = 'missing_browser_headers';
        }

        // Sinal 4: volume alto sustentado ao longo do tempo, sem nunca
        // disparar rajada (scraper "paciente" - caso real em produção:
        // IP com 38 mil visitas em 27 dias, ~1/min, nunca cruzou o sinal
        // 1 acima). Não bloqueia sozinho (mesma mecânica dos outros 3):
        // um IP de volume alto legítimo (NAT/rede compartilhada), sem
        // user-agent de bot nem headers faltando, fica com só este sinal
        // e nunca cruza scraper_signal_threshold/auto_block_signal_threshold
        // sozinho por causa disso.
        $cumulativeThreshold = (int) config('monitor.scraper_cumulative_visits_threshold', 5000);

        if ($visitCount >= $cumulativeThreshold) {
            $signals[] = 'high_cumulative_visits';
        }

        return $signals;
    }

    /**
     * `count($signals) >= scraper_signal_threshold` — mesmo corte usado
     * pelos dois trackers pra decidir `data.flags.scraper`.
     */
    public function isScraper(array $signals): bool
    {
        return count($signals) >= (int) config('monitor.scraper_signal_threshold', 2);
    }
}
