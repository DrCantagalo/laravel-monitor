<?php

use Illuminate\Support\Facades\Route;
use Drcantagalo\LaravelMonitor\Http\Controllers\MonitorController;
use Drcantagalo\LaravelMonitor\Http\Middleware\MonitorCors;

Route::middleware(['api', MonitorCors::class])->prefix('monitor')->group(function () {
    Route::any('/handler', [MonitorController::class, 'handle']);
});

// As antigas rotas públicas de visitante (`GET /monitor/remember-me` e
// `GET /monitor/update-data`) foram removidas em v0.2.0 — nenhuma rota
// HTTP pública era aberta sem o app hospedeiro estar ciente disso. Use
// `Monitor::recognize()`/`Monitor::tag()` (facade, server-side) no lugar.
// Ver CHANGELOG v0.2.0 e README.