<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Http\Request;

class AnonymousVisitorTracker
{
    protected ScraperSignalDetector $scraperSignalDetector;

    protected ScraperBlocker $scraperBlocker;

    protected BlockedIpCleaner $blockedIpCleaner;

    public function __construct(?ScraperSignalDetector $scraperSignalDetector = null, ?ScraperBlocker $scraperBlocker = null, ?BlockedIpCleaner $blockedIpCleaner = null)
    {
        $this->scraperSignalDetector = $scraperSignalDetector ?? new ScraperSignalDetector();
        $this->scraperBlocker = $scraperBlocker ?? new ScraperBlocker();
        $this->blockedIpCleaner = $blockedIpCleaner ?? new BlockedIpCleaner();
    }

    /**
     * laravel-monitor 96: promoção automática de flagged->blocked quando os
     * sinais de scraper da request atual passam de `auto_block_signal_threshold`
     * (separado e mais alto que `scraper_signal_threshold`, que só decide
     * `data.flags.scraper` pra revisão humana - autoblock age sozinho e
     * merece mais confiança). Entra na escada temporária/escalonada de
     * `ScraperBlocker`, não vira permanente direto. Cobrir este tracker (sem
     * sessão) é o caso mais comum pra scrapers de verdade, que tipicamente
     * não carregam sessão.
     */
    protected function maybeAutoBlock(string $ip, array $signals): void
    {
        $threshold = (int) config('monitor.auto_block_signal_threshold', 3);

        if (count($signals) >= $threshold) {
            $this->scraperBlocker->registerOffense($ip, 'auto-signal');
        }
    }

    /**
     * Rastreia o visitante sem sessão (API / bots / scrapers): detecta
     * sinais de scraper e cria/atualiza o registro Monitor associado ao
     * IP.
     */
    public function track(Request $request, string $path, ?string $userAgent, string $ip, bool $notFound = false): void
    {
        $signals = $this->scraperSignalDetector->detect($request, $ip, $userAgent);
        $isScraper = $this->scraperSignalDetector->isScraper($signals);
        IpStat::recordVisit($ip, $isScraper, $signals);
        $this->maybeAutoBlock($ip, $signals);
        $this->blockedIpCleaner->maybeCleanup();

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
}
