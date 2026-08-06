<?php

return [

    'version' => '0.1.13',

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

];
