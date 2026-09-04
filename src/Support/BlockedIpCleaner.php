<?php

namespace Drcantagalo\LaravelMonitor\Support;

use Drcantagalo\LaravelMonitor\Models\BlockedIp;
use Illuminate\Support\Facades\Cache;

/**
 * Limpeza periódica de linhas antigas de `monitor_blocked_ips`
 * (laravel-monitor 97) — não é sobre a aplicação do bloqueio em si (já
 * funciona corretamente desde a 95, via cache curto + filtro em
 * `MonitorMethod::isBlocked()`), é sobre não deixar a tabela crescer pra
 * sempre com entradas expiradas que não têm mais utilidade nem pro
 * decaimento (`ScraperBlocker::registerOffense()` só consulta a linha se o
 * IP ofender de novo).
 *
 * Mesmo padrão que o Laravel já usa pra garbage collection de sessão
 * (`session.lottery`, `Illuminate\Session\Middleware\StartSession::
 * collectGarbage()`), só que baseado em timestamp fixo em vez de
 * probabilidade (pedido explícito do usuário: "de hora em hora",
 * determinístico) - sem exigir que o consumidor do pacote configure um
 * cron próprio. Chamado a cada request rastreada
 * (`SessionVisitorTracker`/`AnonymousVisitorTracker::track()`, mesmo lugar
 * onde a 96 chama `ScraperBlocker::registerOffense()`); só roda a limpeza
 * de fato se já passou `config('monitor.blocked_ips_cleanup_interval_hours')`
 * desde a última vez. Efeito colateral aceito: em site com pouco tráfego,
 * a limpeza só roda na primeira visita depois do intervalo, não num
 * relógio exato - mesma limitação que a GC de sessão do próprio Laravel já
 * tem, não é regressão.
 */
class BlockedIpCleaner
{
    protected const CACHE_KEY = 'monitor:blocked-ips:last-cleanup';

    public function maybeCleanup(): void
    {
        $intervalHours = (int) config('monitor.blocked_ips_cleanup_interval_hours', 1);
        $lastCleanupAt = Cache::get(self::CACHE_KEY);

        if ($lastCleanupAt !== null && (time() - $lastCleanupAt) < $intervalHours * 3600) {
            return;
        }

        $this->cleanup();

        Cache::forever(self::CACHE_KEY, time());
    }

    /**
     * Só apaga (`delete()`, nunca soft delete) linhas de bloqueio
     * TEMPORÁRIO (`blocked_until` não nulo - permanente nunca é tocado)
     * JÁ EXPIRADO (`blocked_until < now()`) cujo decaimento também já
     * teria zerado o histórico recente (`auto_block_strike_decay_cooldown_days`
     * já passou desde `last_offense_at`) E que ofendeu **uma única vez na
     * vida** (`lifetime_offense_count == 1`).
     *
     * Essa última condição é a que protege o fix da laravel-monitor 95
     * (contador de vida inteira separado do que decai, ver
     * `ScraperBlocker`): apagar a linha de um IP com
     * `lifetime_offense_count >= 2` apagaria o histórico de reincidência
     * junto, e um atacante que espaça os ataques ganharia reset de graça a
     * cada ciclo de limpeza - o mesmo furo do platô que a 95 corrigiu, só
     * que por outra porta. IPs com uma única ofensa na vida, sem nenhum
     * padrão, são a grande maioria dos flags isolados (a maioria nunca
     * reincide) - a tabela continua pequena na prática mesmo guardando pra
     * sempre quem tem 2+ ofensas.
     */
    protected function cleanup(): void
    {
        $cooldownDays = (int) config('monitor.auto_block_strike_decay_cooldown_days', 30);

        BlockedIp::whereNotNull('blocked_until')
            ->where('blocked_until', '<', now())
            ->where('last_offense_at', '<', now()->subDays($cooldownDays))
            ->where('lifetime_offense_count', 1)
            ->delete();
    }
}
