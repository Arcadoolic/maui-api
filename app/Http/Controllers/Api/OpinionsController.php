<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Requests\Api\PutOpinionsRequest;
use App\Services\Opinions\OpinionIntake;
use Illuminate\Http\JsonResponse;

/** Votes and play counts of the calling cabinet's games (docs/DECISIONS.md D75). */
final class OpinionsController
{
    public function __invoke(PutOpinionsRequest $request, OpinionIntake $intake): JsonResponse
    {
        return new JsonResponse($intake->take(AuthenticateCabinet::client($request), $request->opinions()));
    }
}
