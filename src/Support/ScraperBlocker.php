<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;

/**
 * Bloqueio temporário e escalonado (laravel-monitor 95), inspirado em
 * fail2ban/CrowdSec: cada ofensa aumenta a duração do bloqueio
 * exponencialmente, reincidência sustentada eventualmente vira permanente,
 * e um período sem ofensas decai o contador de volta. Só usado pelo
 * caminho automático (honeypot, sinais de scraper — tasks 96/97); o
 * bloqueio manual (`updateBlockedIps`/`blockIps`) continua permanente e
 * não passa por aqui, ver README "Manual IP blocking".
 */
class ScraperBlocker
{
    /**
     * Registra uma ofensa desse IP, aplicando decaimento (se aplicável),
     * incrementando `strike_count` e recalculando `blocked_until` — cria a
     * linha em `monitor_blocked_ips` se ainda não existir. Retorna o model
     * já salvo.
     */
    public function registerOffense(string $ip, string $source): BlockedIp
    {
        $blockedIp = BlockedIp::firstOrNew(['ip' => $ip]);

        $strikeCount = $blockedIp->exists ? (int) $blockedIp->strike_count : 0;

        // Decaimento: só se a linha já existia com uma ofensa anterior
        // registrada. Cada período de cooldown completo desde a última
        // ofensa reduz um strike, nunca abaixo de 1.
        if ($blockedIp->exists && $blockedIp->last_offense_at !== null) {
            $cooldownDays = (int) config('monitor.auto_block_strike_decay_cooldown_days', 30);
            $periodsElapsed = intdiv($blockedIp->last_offense_at->diffInDays(now()), $cooldownDays);
            $strikeCount = max(1, $strikeCount - $periodsElapsed);
        }

        $strikeCount++;

        $permanentAfterStrikes = (int) config('monitor.auto_block_permanent_after_strikes', 10);

        $blockedIp->source = $source;
        $blockedIp->strike_count = $strikeCount;
        $blockedIp->last_offense_at = now();
        $blockedIp->blocked_until = $strikeCount >= $permanentAfterStrikes
            ? null
            : now()->addHours(2 ** $strikeCount);

        $blockedIp->save();

        return $blockedIp;
    }
}
