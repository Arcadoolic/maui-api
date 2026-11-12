<?php

namespace App\Http\Controllers\Api;

use App\Models\Player;
use App\Services\Leaderboards\GlobalRanking;
use App\Services\Leaderboards\Standing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The global podium of the hiscores front (D70) for a cabinet, its hiscores board
 * (docs/DECISIONS.md D100): the players with their avatars, which the cabinet
 * downloads as it does those of the leaderboards (D53).
 */
final class RankingController
{
    public const MAX_PLAYERS = 50;

    public const DEFAULT_PLAYERS = 25;

    public function __invoke(Request $request, GlobalRanking $ranking): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PLAYERS]]);
        $standings = $ranking->standings()->take((int) ($validated['limit'] ?? self::DEFAULT_PLAYERS));
        $players = Player::query()->whereIn('id', $standings->map(fn (Standing $standing): int => $standing->playerId))->get()->keyBy('id');

        return new JsonResponse([
            'ranking' => $standings->map(function (Standing $standing) use ($players): array {
                $player = $players->get($standing->playerId);

                return [
                    'rank' => $standing->rank,
                    'player' => [
                        'id' => (string) $player?->uuid,
                        'pseudo_3' => (string) $player?->pseudo_3,
                        'avatar' => $player?->avatar_hash,
                    ],
                    'points' => $standing->points,
                    'games' => $standing->games,
                    'crowns' => $standing->crowns,
                    'podiums' => $standing->podiums,
                ];
            })->values()->all(),
        ]);
    }
}
