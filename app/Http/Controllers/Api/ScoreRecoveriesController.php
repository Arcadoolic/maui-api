<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScoreRecoveryStatus;
use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Problems\ApiProblemException;
use App\Http\Requests\Api\ReportScoreRecoveryRequest;
use App\Models\ScoreRecovery;
use App\Services\Scores\ScoreRecoveries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The requests to recover a score, read by the cabinet they are meant for
 * (docs/DECISIONS.md D93). A found score is sent through POST /scores; a
 * missing one is reported here.
 */
final class ScoreRecoveriesController
{
    public function index(Request $request): JsonResponse
    {
        $recoveries = ScoreRecovery::query()->open()
            ->where('client_id', AuthenticateCabinet::client($request)->id)
            ->with('player')
            ->orderBy('id')
            ->get();

        return new JsonResponse(['recoveries' => $recoveries->map(fn (ScoreRecovery $recovery): array => [
            'id' => $recovery->uuid,
            'player_id' => $recovery->player->uuid,
            'romname' => $recovery->romname,
            'table' => $recovery->table,
            'score' => $recovery->score,
            'achieved_at' => $recovery->achieved_at->toIso8601ZuluString(),
        ])->all()]);
    }

    public function result(ReportScoreRecoveryRequest $request, ScoreRecoveries $recoveries, string $recovery): Response
    {
        $client = AuthenticateCabinet::client($request);
        DB::transaction(function () use ($request, $recoveries, $recovery, $client): void {
            $found = Str::isUuid($recovery)
                ? ScoreRecovery::query()->where('uuid', strtolower($recovery))->where('client_id', $client->id)->lockForUpdate()->first()
                : null;
            if ($found === null) {
                throw ApiProblemException::recoveryNotFound();
            }
            if (! $found->isOpen()) {
                throw ApiProblemException::recoveryClosed();
            }
            $recoveries->close($found, ScoreRecoveryStatus::NotFound, [
                'reason' => $request->string('reason')->toString(),
                'table' => $request->validated('table'),
            ]);
        });

        return response()->noContent();
    }
}
