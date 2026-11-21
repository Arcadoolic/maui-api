<?php

namespace App\Http\Controllers\Front;

use App\Models\Player;
use App\Services\Leaderboards\RankingHistory;
use Illuminate\Http\JsonResponse;

/** The places and points of the global podium's first players, day by day (docs/DECISIONS.md D97, D104). */
final class RankingHistoryController
{
    public function __invoke(RankingHistory $history): JsonResponse
    {
        $top = $history->top();
        $players = Player::query()->whereIn('id', array_column($top, 'player_id'))->get()->keyBy('id');

        return new JsonResponse([
            'timezone' => RankingHistory::timezone(),
            'players' => array_map(fn (array $entry): array => [
                'player' => GamesController::player($players->get($entry['player_id'])),
                'rank' => $entry['rank'],
                'points' => $entry['points'],
                'history' => $entry['history'],
                'points_history' => $entry['points_history'],
            ], $top),
        ]);
    }
}
