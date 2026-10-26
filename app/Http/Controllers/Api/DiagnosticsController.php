<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Requests\Api\StoreDiagnosticRequest;
use App\Services\Diagnostics\DiagnosticIntake;
use Illuminate\Http\JsonResponse;

/** Diagnostics sent from a cabinet's back office (docs/DECISIONS.md D92). */
final class DiagnosticsController
{
    public function __construct(private readonly DiagnosticIntake $intake) {}

    public function store(StoreDiagnosticRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $report */
        $report = json_decode($request->string('report')->toString(), true) ?: [];
        $comment = $request->input('comment');
        $diagnostic = $this->intake->take(
            AuthenticateCabinet::client($request),
            $request->string('romname')->toString(),
            is_string($comment) ? $comment : null,
            $report,
            $request->diagnosticFiles(),
        );

        return new JsonResponse([
            'id' => $diagnostic->uuid,
            'received_at' => $diagnostic->received_at->toIso8601ZuluString(),
        ], JsonResponse::HTTP_CREATED);
    }
}
