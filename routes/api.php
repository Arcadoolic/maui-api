<?php

use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\StartupController;
use Illuminate\Support\Facades\Route;

// MAUI machine API, served under /api/v1 (see bootstrap/app.php).
// Contract: docs/openapi.yaml.

Route::middleware(['throttle:cabinet', 'cabinet:session'])->group(function () {
    Route::get('ping', PingController::class);
    Route::post('startups', StartupController::class);
    Route::post('heartbeat', HeartbeatController::class);
});
