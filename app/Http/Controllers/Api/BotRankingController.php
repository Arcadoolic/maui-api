<?php

namespace App\Http\Controllers\Api;

use App\Models\Player;
use App\Services\Leaderboards\GlobalRanking;
use App\Services\Leaderboards\Standing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The global podium of the hiscores front (D70), for the bots (docs/DECISIONS.md D89). */
final class BotRankingController
{
    public const MAX_PLAYERS = 25;

    public const DEFAULT_PLAYERS = 10;

    public function __invoke(Request $request, GlobalRanking $ranking): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PLAYERS]]);
        $standings = $ranking->standings()->take((int) ($validated['limit'] ?? self::DEFAULT_PLAYERS));
        $players = Player::query()->whereIn('id', $standings->map(fn (Standing $standing): int => $standing->playerId))->get()->keyBy('id');

        return new JsonResponse([
            'ranking' => $standings->map(fn (Standing $standing): array => [
                'rank' => $standing->rank,
                'player' => [
                    'id' => (string) $players->get($standing->playerId)?->uuid,
                    'pseudo_3' => (string) $players->get($standing->playerId)?->pseudo_3,
                ],
                'points' => $standing->points,
                'games' => $standing->games,
                'crowns' => $standing->crowns,
                'podiums' => $standing->podiums,
            ])->values()->all(),
        ]);
    }
}
