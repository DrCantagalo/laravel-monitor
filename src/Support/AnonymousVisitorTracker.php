<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\IpStat;
use Drcantagalo\LaravelMonitor\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $visitCount = IpStat::visitCount($ip) + 1;
        $signals = $this->scraperSignalDetector->detect($request, $ip, $userAgent, $visitCount);
        $isScraper = $this->scraperSignalDetector->isScraper($signals);
        IpStat::recordVisit($ip, $isScraper, $signals);
        $this->maybeAutoBlock($ip, $signals);
        $this->blockedIpCleaner->maybeCleanup();
        DataPruner::maybeCleanup();

        // laravel-monitor 141: era `Monitor::where('data->ips', 'like',
        // "%{$ip}%")->first()` — scan não-indexado na coluna JSON a cada
        // request anônima, o caminho mais quente do pacote. `monitor_visit_ips`
        // (task 104, gravada direto por Monitor::recordIp() desde 0.42.0) é
        // o mesmo mapeamento IP->Monitor, indexado.
        $monitorId = DB::table('monitor_visit_ips')->where('ip', $ip)->value('monitor_id');
        $user = $monitorId ? Monitor::find($monitorId) : null;

        if ($user) {
            // O IP já está em `monitor_visit_ips` (foi assim que este
            // Monitor foi achado), então não há `recordIp()` a fazer aqui.
            $data = $user->data;

            $data['flags'] = $data['flags'] ?? [];
            $data['flags']['scraper'] = $isScraper;
            $data['flags']['scraper_signals'] = $signals;

            $user->data = $data;
            $user->recordHit($path, $notFound);

            // touch(), não save(): ver comentário equivalente em
            // SessionVisitorTracker — `updated_at` é a última atividade.
            $user->touch();

            return;
        }

        $data = [
            'ua'    => $userAgent,
            'flags' => [
                'scraper'         => $isScraper,
                'scraper_signals' => $signals,
            ],
        ];

        $user = Monitor::create(['data' => $data]);
        $user->recordHit($path, $notFound);
        $user->recordIp($ip);
    }
}
