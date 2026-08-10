<?php

return [

    'version' => '0.1.18',

    // Nome do cookie de longa duração usado para reconhecer visitantes
    // recorrentes (fluxo "remember me"). Ver README para o contrato do
    // front-end.
    'remember_cookie' => 'monitor_id_token',

    // Duração do cookie acima, em dias.
    'remember_cookie_days' => 1825,

    // Origem (scheme + host) autorizada a chamar as rotas monitor/* via
    // CORS direto do navegador (dashboard do home-page). Ver README.
    'dashboard_origin' => 'https://monitor.cantagalo.it',

    // TTL, em minutos, do token de leitura efêmero emitido por
    // issueReadToken.
    'read_token_ttl_minutes' => 15,

    // Heurística de detecção de scraper (MonitorMethod::detectScraperSignals),
    // aplicada só a requests sem sessão. Cada sinal abaixo, se disparado,
    // conta pra scraper_signal_threshold; não bloqueia nada sozinho, só
    // marca data.flags.scraper (bloqueio de IP é feature separada).

    // Janela (segundos) e limite de requests do mesmo IP dentro dela pra
    // disparar o sinal de alta frequência.
    'scraper_frequency_window_seconds' => 10,
    'scraper_frequency_threshold' => 5,

    // Substrings (case-insensitive) de User-Agent conhecidos de bots/
    // scrapers/crawlers. Front-end pode sobrescrever via config publish.
    'scraper_known_bot_user_agents' => [
        'bot', 'spider', 'crawl', 'scrapy', 'curl', 'wget',
        'python-requests', 'python-urllib', 'go-http-client', 'java/',
        'libwww-perl', 'httpclient', 'headlesschrome', 'phantomjs',
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'petalbot',
    ],

    // Quantos sinais disparados são necessários pra marcar data.flags.scraper.
    'scraper_signal_threshold' => 2,

    // TTL (segundos) do cache de lookup de IP bloqueado
    // (MonitorMethod::isBlocked), pra evitar uma query em monitor_blocked_ips
    // a cada request. Invalidado por IP ao bloquear via updateBlockedIps.
    'blocked_ip_cache_ttl' => 60,

];
