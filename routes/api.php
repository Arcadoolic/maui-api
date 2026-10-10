<?php

use App\Http\Controllers\Api\CatalogGamesController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\LeaderboardsController;
use App\Http\Controllers\Api\OpinionsController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\PlayersController;
use App\Http\Controllers\Api\RepositoryAuthorizeController;
use App\Http\Controllers\Api\RepositoryController;
use App\Http\Controllers\Api\ScoreEventsController;
use App\Http\Controllers\Api\ScoresController;
use App\Http\Controllers\Api\StartupController;
use App\Http\Controllers\Front\AuthController;
use App\Http\Controllers\Front\EventsController;
use App\Http\Controllers\Front\GamesController;
use App\Http\Controllers\Front\MeController;
use App\Http\Controllers\Front\PlayersController as FrontPlayersController;
use App\Http\Controllers\Front\RankingController;
use App\Services\Members\MemberInvitationIssuer;
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
    // Votes and play counts of the cabinet's games (docs/DECISIONS.md D75).
    Route::put('opinions', OpinionsController::class);
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

// Bots (Discord): what the scores changed on the leaderboards (docs/DECISIONS.md D60).
Route::middleware(['throttle:service', 'service:events:read'])->group(function () {
    Route::get('bot/events', [ScoreEventsController::class, 'index']);
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

// Hiscores front (maui-hifront): members logged in with Discord, a session
// instead of a token (docs/DECISIONS.md D64). Reached through the front's own
// server, never from another origin.
Route::prefix('front')->middleware('front')->group(function () {
    Route::middleware('throttle:front-auth')->group(function () {
        Route::get('auth/discord', [AuthController::class, 'redirect']);
        Route::get('auth/discord/callback', [AuthController::class, 'callback']);
        Route::get('invitations/{token}', [AuthController::class, 'invitation'])
            ->where('token', '[A-Za-z0-9]{'.MemberInvitationIssuer::TOKEN_LENGTH.'}');
    });

    Route::middleware(['member', 'throttle:front'])->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::post('logout', [AuthController::class, 'logout']);
        // The player of the member, linked with initials + PIN (docs/DECISIONS.md D66, D71).
        Route::post('me/player', [MeController::class, 'linkPlayer'])->middleware('throttle:front-player-link');
        Route::delete('me/player', [MeController::class, 'unlinkPlayer']);

        // Reading: games, players and the event feed (docs/DECISIONS.md D67).
        Route::get('games', [GamesController::class, 'index']);
        Route::get('games/filters', [GamesController::class, 'filters']);
        Route::get('games/highlights', [GamesController::class, 'highlights']);
        Route::get('games/missed-dates', [GamesController::class, 'missedDates']);
        Route::get('games/{romname}', [GamesController::class, 'show']);
        Route::get('games/{romname}/media/{type}', [GamesController::class, 'media']);
        Route::get('players', [FrontPlayersController::class, 'index']);
        Route::get('players/{player}', [FrontPlayersController::class, 'show']);
        Route::get('players/{player}/avatar', [FrontPlayersController::class, 'avatar']);
        Route::get('players/{player}/games/{romname}', [FrontPlayersController::class, 'history']);
        Route::get('events', [EventsController::class, 'index']);
        // The global podium (docs/DECISIONS.md D70).
        Route::get('ranking', RankingController::class);
    });
});
