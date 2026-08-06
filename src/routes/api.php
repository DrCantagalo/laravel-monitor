<?php

use Illuminate\Support\Facades\Route;
use Drcantagalo\LaravelMonitor\Http\Controllers\MonitorController;

Route::middleware('api')->prefix('monitor')->group(function () {
    Route::any('/handler', [MonitorController::class, 'handle']);
});

// Ação pública do visitante (precisa de sessão de cookies, por isso 'web'
// em vez de 'api'). GET pra não exigir CSRF token do app hospedeiro.
Route::middleware('web')->prefix('monitor')->group(function () {
    Route::get('/remember-me', [MonitorController::class, 'rememberMe']);
});