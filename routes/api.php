<?php

use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\RepositoryAuthorizeController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\StartupController;
use Illuminate\Support\Facades\Route;

// MAUI machine API, served under /api/v1 (see bootstrap/app.php).
// Contract: docs/openapi.yaml.

Route::middleware(['throttle:cabinet', 'cabinet:session'])->group(function () {
    Route::get('ping', PingController::class);
    Route::post('startups', StartupController::class);
    Route::post('heartbeat', HeartbeatController::class);
});

// Starting-pack repository (docs/DECISIONS.md D46). The URL is looked up once
// per action; authorize is called by the repository's Caddy (forward_auth) on
// every request, Range requests of an import included: separate, wider limit.
Route::middleware('repository')->group(function () {
    Route::get('repository', RepositoryController::class)->middleware('throttle:cabinet');
    Route::get('repository/authorize', RepositoryAuthorizeController::class)->middleware('throttle:repository');
});
