<?php

namespace App\Http\Controllers\Front;

use App\Models\Player;
use App\Services\Leaderboards\GlobalRanking;
use App\Services\Leaderboards\Standing;
use Illuminate\Http\JsonResponse;

/** The global podium of the hiscores front, with the rule it follows (docs/DECISIONS.md D70). */
final class RankingController
{
    public function __invoke(GlobalRanking $ranking): JsonResponse
    {
        $standings = $ranking->standings();
        $players = Player::query()->whereIn('id', $standings->map(fn (Standing $standing): int => $standing->playerId))->get()->keyBy('id');

        return new JsonResponse([
            'rules' => GlobalRanking::rules(),
            'ranking' => $standings->map(fn (Standing $standing): array => [
                'rank' => $standing->rank,
                'player' => GamesController::player($players->get($standing->playerId)),
                'points' => $standing->points,
                'counted' => $standing->counted(),
                'games' => $standing->games,
                'crowns' => $standing->crowns,
                'podiums' => $standing->podiums,
            ])->all(),
        ]);
    }
}
