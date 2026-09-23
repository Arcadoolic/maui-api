<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Requests\Api\StoreStartupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class StartupController
{
    public function __invoke(StoreStartupRequest $request): JsonResponse
    {
        $client = AuthenticateCabinet::client($request);

        $startup = DB::transaction(function () use ($client, $request) {
            $startup = $client->startups()->create([
                ...$request->safe()->except('client_datetime'),
                // Eloquent drops the offset when serializing dates: store UTC.
                'client_datetime' => Carbon::parse($request->string('client_datetime')->toString())->utc(),
                'received_at' => now(),
            ]);

            $client->recordHeartbeat();

            return $startup;
        });

        return new JsonResponse([
            'id' => $startup->id,
            'received_at' => $startup->received_at->toIso8601ZuluString(),
        ], JsonResponse::HTTP_CREATED);
    }
}
