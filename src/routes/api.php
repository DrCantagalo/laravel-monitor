<?php

use Illuminate\Support\Facades\Route;
use Monitor\Http\Controllers\MonitorController;

Route::middleware('api')->prefix('monitor')->group(function () {
    Route::any('/handler', [MonitorController::class, 'handle']);
});