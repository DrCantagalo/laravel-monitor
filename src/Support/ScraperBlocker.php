<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Illuminate\Support\Facades\Cache;

/**
 * Bloqueio temporário e escalonado (laravel-monitor 95), inspirado em
 * fail2ban/CrowdSec: cada ofensa aumenta a duração do bloqueio
 * exponencialmente, e um período sem ofensas decai o contador de volta. Só
 * usado pelo caminho automático (honeypot, sinais de scraper — tasks
 * 96/97); o bloqueio manual (`updateBlockedIps`/`blockIps`) continua
 * permanente e não passa por aqui, ver README "Manual IP blocking".
 *
 * Duas contagens separadas de propósito, não uma só — achado discutindo
 * o desenho depois do primeiro rascunho: com uma contagem só que decai E
 * decide o permanente, qualquer reincidência espaçada em >= 1 período de
 * cooldown sempre volta pro piso (1) antes de somar a ofensa nova — a
 * partir de strike_count=2 isso trava nesse valor pra sempre (2 - 1 = 1,
 * soma 1, volta a 2), não importa quantas vezes o IP reincida ao longo
 * dos anos. Um atacante paciente que espaça os ataques nunca alcançaria o
 * threshold de permanente. Fix: `strike_count` decai e dirige só a
 * DURAÇÃO de cada bloqueio individual (cada incidente isolado é mesmo de
 * baixo risco, isso está certo); `lifetime_offense_count` nunca decai, só
 * soma, e é quem dirige o threshold de PERMANENTE — mesmo o atacante bem
 * espaçado eventualmente vira permanente, só demora mais (justo: reincidir
 * 1x/mês por 2 anos é menos grave que reincidir todo dia, mas ainda deveria
 * terminar em bloqueio definitivo se continuar pra sempre).
 */
class ScraperBlocker
{
    /**
     * Registra uma ofensa desse IP, aplicando decaimento a `strike_count`
     * (se aplicável), incrementando `strike_count` e `lifetime_offense_count`,
     * e recalculando `blocked_until` — cria a linha em `monitor_blocked_ips`
     * se ainda não existir. Retorna o model já salvo.
     */
    public function registerOffense(string $ip, string $source): BlockedIp
    {
        $blockedIp = BlockedIp::firstOrNew(['ip' => $ip]);

        $strikeCount = $blockedIp->exists ? (int) $blockedIp->strike_count : 0;
        $lifetimeOffenseCount = $blockedIp->exists ? (int) $blockedIp->lifetime_offense_count : 0;

        // Decaimento: só afeta strike_count (duração), nunca
        // lifetime_offense_count (permanente) — só se a linha já existia
        // com uma ofensa anterior registrada. Cada período de cooldown
        // completo desde a última ofensa reduz um strike, nunca abaixo de 1.
        if ($blockedIp->exists && $blockedIp->last_offense_at !== null) {
            $cooldownDays = (int) config('monitor.auto_block_strike_decay_cooldown_days', 30);
            $periodsElapsed = intdiv($blockedIp->last_offense_at->diffInDays(now()), $cooldownDays);
            $strikeCount = max(1, $strikeCount - $periodsElapsed);
        }

        $strikeCount++;
        $lifetimeOffenseCount++;

        $permanentAfterLifetimeOffenses = (int) config('monitor.auto_block_permanent_after_lifetime_offenses', 10);

        $blockedIp->source = $source;
        $blockedIp->strike_count = $strikeCount;
        $blockedIp->lifetime_offense_count = $lifetimeOffenseCount;
        $blockedIp->last_offense_at = now();
        $blockedIp->blocked_until = $lifetimeOffenseCount >= $permanentAfterLifetimeOffenses
            ? null
            : now()->addHours(2 ** $strikeCount);

        $blockedIp->save();

        // Sem isso, um IP recém-bloqueado automaticamente (honeypot ou
        // threshold de sinais, task 96) continuaria passando por
        // MonitorMethod::isBlocked() por até blocked_ip_cache_ttl (default
        // 60s) depois do registro - o cache já era invalidado nos
        // caminhos manuais (updateBlockedIps/flagScraperPath chamam
        // Cache::forget explicitamente), mas nada fazia isso pro caminho
        // automático até agora, já que nada chamava registerOffense() a
        // partir do tráfego real antes da task 96 existir (a 95 só testava
        // a classe isolada).
        Cache::forget("monitor:blocked-ip:{$ip}");

        return $blockedIp;
    }
}
