<?php

return [

    'version' => '0.11.0',

    // Nome da chave de sessão usada por `Monitor::skipTracking()` (Facade
    // em src/Facades/Monitor.php) pra marcar a request atual como "não
    // rastrear" - lida por SessionVisitorTracker::track() e apagada assim
    // que consumida. Ver README, seção "Uso avançado".
    'skip_session_key' => 'avoid_monitor',

    // Grava data['user_id'] (Auth::id()) na linha do Monitor do
    // dispositivo/sessão atual sempre que Auth::check() for true - só uma
    // tag a mais no JSON, nunca funde/reatribui a linha em si (1 linha
    // continua = 1 dispositivo/navegador reconhecido via remember_cookie,
    // ver SessionVisitorTracker::track()). Opt-out: apps sem Auth
    // configurado, ou que não querem esse dado por política de
    // privacidade, podem desligar com false. Ver README, seção
    // "Authenticated user tagging".
    'track_authenticated_user' => true,

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

    // Heurística de detecção de scraper (ScraperSignalDetector::detect,
    // compartilhada por AnonymousVisitorTracker e SessionVisitorTracker —
    // requests com ou sem sessão). Cada sinal abaixo, se disparado, conta
    // pra scraper_signal_threshold; não bloqueia nada sozinho, só marca
    // data.flags.scraper (bloqueio de IP é feature separada).

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

    // TTL (segundos) do cache de lookup de IP/path bloqueado
    // (MonitorMethod::isBlocked/isPathBlocked), pra evitar uma query em
    // monitor_blocked_ips/monitor_blocked_paths a cada request. Invalidado
    // por IP/path ao bloquear via updateBlockedIps/flagScraperPath.
    'blocked_ip_cache_ttl' => 60,

    // TTL (minutos) do cache da action getPages (resultado já agregado e
    // paginado). Chave inclui um contador de versão incrementado em
    // flagScraperPath/unflagPath (não há suporte a Cache::tags() nos
    // drivers array/file), então uma mutação invalida todas as
    // combinações de parâmetros de uma vez, mesmo as que ainda não
    // expiraram pelo TTL.
    'pages_cache_ttl_minutes' => 5,

    // TTL (minutos) do cache de getVisitorsByIp/getBlockedIps/
    // getBlockedPaths. Mesmo esquema de versão que pages_cache_ttl_minutes
    // (contador próprio, monitor:listings:version), incrementado em
    // updateBlockedIps/unblockIp/flagScraperPath/unflagPath.
    'listings_cache_ttl_minutes' => 5,

    // Regeneração automática do arquivo de deny-list (ver
    // `monitor:export-denylist`) sempre que monitor_blocked_ips muda
    // (updateBlockedIps/unblockIp/flagScraperPath). Opt-in: default false
    // - uma instalação nova não passa a escrever arquivo no disco sem o
    // consumidor do pacote decidir ligar isso explicitamente. O comando
    // artisan continua funcionando manualmente independente desta flag.
    'denylist_auto_export' => false,

    // Formato usado por monitor:export-denylist quando --format não é
    // passado, e pela auto-exportação acima (que não tem como receber
    // flag de CLI). 'apache' ou 'nginx'.
    'denylist_format' => 'apache',

    // Path absoluto do arquivo de deny-list gerado.
    'denylist_path' => storage_path('app/monitor/denylist.conf'),

    // TTL (segundos) do cache de blockedAttemptsTotal (exposto em
    // getData) e getBlockResults. Fixo e curto de propósito, *fora* do
    // esquema versionado (monitor:pages:version/monitor:listings:version)
    // usado por getPages/getVisitorsByIp/etc — aquele esquema assume
    // mutação rara (ação manual de admin bloqueando/desbloqueando algo);
    // monitor_block_results incrementa a cada request bloqueada (um bot
    // martelando um endpoint flagado pode gerar centenas por segundo), e
    // bumpar uma versão de cache compartilhada a cada uma delas
    // invalidaria getVisitorsByIp/getBlockedIps/etc pra todo mundo sem
    // necessidade.
    'block_results_cache_ttl_seconds' => 45,

    // TTL (segundos) do cache de visitors_total/visits_total/
    // sessions_total/unique_ips_total (agregados de getData desde a
    // 0.10.0, ver README "Aggregated dashboard totals"). Mesmo raciocínio
    // de block_results_cache_ttl_seconds: mutação a cada request
    // rastreada (Monitor::newVisit/IpStat::recordVisit), fora do esquema
    // versionado de getPages/getVisitorsByIp.
    'data_totals_cache_ttl_seconds' => 45,

];
