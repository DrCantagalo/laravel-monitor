<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Http\Request;

class AnonymousVisitorTracker
{
    protected ScraperSignalDetector $scraperSignalDetector;

    public function __construct(?ScraperSignalDetector $scraperSignalDetector = null)
    {
        $this->scraperSignalDetector = $scraperSignalDetector ?? new ScraperSignalDetector();
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
