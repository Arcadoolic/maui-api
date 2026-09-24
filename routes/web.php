<?php

use App\Http\Controllers\InvitationController;
use App\Http\Middleware\SecureInvitationPages;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Credentials retrieval by cabinet owners (docs/PLAN.md 1.3).
Route::middleware(['throttle:invitations', SecureInvitationPages::class])
    ->prefix('invite/{token}')
    ->where(['token' => '[A-Za-z0-9]{32,128}'])
    ->name('invitations.')
    ->group(function () {
        Route::get('/', [InvitationController::class, 'show'])->name('show');
        Route::post('claim', [InvitationController::class, 'claim'])->name('claim');
    });
