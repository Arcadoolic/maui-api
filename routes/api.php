<?php

use App\Http\Controllers\Api\CatalogGamesController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\LeaderboardsController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\PlayersController;
use App\Http\Controllers\Api\RepositoryAuthorizeController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\ScoresController;
use App\Http\Controllers\Api\StartupController;
use Illuminate\Support\Facades\Route;

// MAUI machine API, served under /api/v1 (see bootstrap/app.php).
// Contract: docs/openapi.yaml.

Route::middleware(['throttle:cabinet', 'cabinet:session'])->group(function () {
    Route::get('ping', PingController::class);
    Route::post('startups', StartupController::class);
    Route::post('heartbeat', HeartbeatController::class);
});

// Players of the cabinet (docs/DECISIONS.md D48).
Route::middleware(['throttle:cabinet', 'cabinet:players'])->group(function () {
    Route::get('players', [PlayersController::class, 'index']);
    Route::get('players/availability', [PlayersController::class, 'availability']);
    Route::post('players', [PlayersController::class, 'store']);
    Route::post('players/link', [PlayersController::class, 'link'])->middleware('throttle:player-link');
    Route::patch('players/{player}', [PlayersController::class, 'update']);
    Route::post('players/{player}/pin', [PlayersController::class, 'regeneratePin']);
    Route::delete('players/{player}/link', [PlayersController::class, 'unlink']);
    Route::post('players/{player}/avatar', [PlayersController::class, 'storeAvatar']);
});

// Scores of the cabinet's players: personal bests only (docs/DECISIONS.md D50).
Route::middleware(['throttle:cabinet', 'cabinet:scores:write'])->group(function () {
    Route::post('scores', [ScoresController::class, 'store']);
});

// Shared leaderboards, with an ETag (docs/DECISIONS.md D52).
Route::middleware(['throttle:cabinet', 'cabinet:scores:read', 'etag'])->group(function () {
    Route::get('leaderboards', [LeaderboardsController::class, 'index']);
    Route::get('leaderboards/{romname}', [LeaderboardsController::class, 'show']);
    Route::get('players/{player}/bests', [LeaderboardsController::class, 'bests']);
});

// Avatars carry their own ETag, the hash of the PNG (docs/DECISIONS.md D53).
Route::middleware(['throttle:cabinet', 'cabinet:scores:read'])->group(function () {
    Route::get('players/{player}/avatar', [LeaderboardsController::class, 'avatar']);
});

// Bots (Discord): the same leaderboards, without a machine (docs/DECISIONS.md D57).
Route::middleware(['throttle:service', 'service:leaderboards:read'])->group(function () {
    Route::get('bot/leaderboards', [LeaderboardsController::class, 'available']);
    Route::get('bot/leaderboards/{romname}', [LeaderboardsController::class, 'show']);
});

// Starting-pack repository (docs/DECISIONS.md D46). The URL is looked up once
// per action; authorize is called by the repository's Caddy (forward_auth) on
// every request, Range requests of an import included: separate, wider limit.
Route::middleware('repository')->group(function () {
    Route::get('repository', RepositoryController::class)->middleware('throttle:cabinet');
    Route::get('repository/authorize', RepositoryAuthorizeController::class)->middleware('throttle:repository');
});

// Service accounts: game catalog pushed by maui-repository (docs/DECISIONS.md D47).
Route::middleware(['throttle:service', 'service:catalog:write'])->group(function () {
    Route::put('catalog/games', CatalogGamesController::class);
});
