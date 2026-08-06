<?php

use Illuminate\Support\Facades\Route;
use Drcantagalo\LaravelMonitor\Http\Controllers\MonitorController;
use Drcantagalo\LaravelMonitor\Http\Middleware\MonitorCors;

Route::middleware(['api', MonitorCors::class])->prefix('monitor')->group(function () {
    Route::any('/handler', [MonitorController::class, 'handle']);
});

// Ação pública do visitante (precisa de sessão de cookies, por isso 'web'
// em vez de 'api'). GET pra não exigir CSRF token do app hospedeiro.
Route::middleware('web')->prefix('monitor')->group(function () {
    Route::get('/remember-me', [MonitorController::class, 'rememberMe']);
});