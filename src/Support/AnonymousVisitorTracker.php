<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AnonymousVisitorTracker
{
    /**
     * Rastreia o visitante sem sessão (API / bots / scrapers): detecta
     * sinais de scraper e cria/atualiza o registro Monitor associado ao
     * IP.
     */
    public function track(Request $request, string $path, ?string $userAgent, string $ip, bool $notFound = false): void
    {
        $signals = $this->detectScraperSignals($request, $ip, $userAgent);
        $isScraper = count($signals) >= config('monitor.scraper_signal_threshold', 2);

        $user = Monitor::where('data->ips', 'like', "%{$ip}%")->first();

        if ($user) {
            $data = $user->data;
            $data['page'][$path] = ($data['page'][$path] ?? 0) + 1;

            $ips = $data['ips'] ?? [];
            if (!in_array($ip, $ips)) {
                $ips[] = $ip;
                $data['ips'] = $ips;
            }

            $data['flags'] = $data['flags'] ?? [];
            $data['flags']['scraper'] = $isScraper;
            $data['flags']['scraper_signals'] = $signals;

            if ($notFound) {
                $data['not_found'] = $data['not_found'] ?? [];
                $data['not_found'][$path] = true;
            }

            $user->data = $data;
            $user->save();

            return;
        }

        $data = [
            'page'     => [$path => 1],
            'sessions' => [],
            'ips'      => [$ip],
            'ua'       => $userAgent,
            'flags'    => [
                'scraper'         => $isScraper,
                'scraper_signals' => $signals,
            ],
        ];

        if ($notFound) {
            $data['not_found'] = [$path => true];
        }

        Monitor::create(['data' => $data]);
    }

    /**
     * Heurística de detecção de scraper/bot para requests sem sessão.
     * Combina sinais já disponíveis no request; cada sinal disparado entra
     * na lista retornada, e quem decide o que fazer com isso (marcar,
     * bloquear, etc.) é o chamador — aqui só detectamos.
     *
     * @return string[] lista dos sinais disparados
     */
    protected function detectScraperSignals(Request $request, string $ip, ?string $userAgent): array
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

        return $signals;
    }
}
