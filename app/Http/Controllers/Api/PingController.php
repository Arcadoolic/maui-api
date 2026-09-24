<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PingController
{
    public function __invoke(Request $request): JsonResponse
    {
        $client = AuthenticateCabinet::client($request);

        return new JsonResponse([
            'client' => [
                'key' => $client->public_key,
                'name' => $client->name,
            ],
            'machine' => [
                'bound_at' => $client->bound_at?->toIso8601ZuluString(),
                'newly_bound' => AuthenticateCabinet::binding($request)->newlyBound,
            ],
            'server_time' => now()->toIso8601ZuluString(),
        ]);
    }
}
