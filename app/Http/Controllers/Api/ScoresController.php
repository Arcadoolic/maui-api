<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Requests\Api\StoreScoresRequest;
use App\Services\Scores\ScoreIntake;
use Illuminate\Http\JsonResponse;

/** Scores sent by the calling cabinet (docs/DECISIONS.md D50). */
final class ScoresController
{
    public function __construct(private readonly ScoreIntake $intake) {}

    public function store(StoreScoresRequest $request): JsonResponse
    {
        return new JsonResponse([
            'results' => $this->intake->take(AuthenticateCabinet::client($request), $request->scores()),
        ]);
    }
}
