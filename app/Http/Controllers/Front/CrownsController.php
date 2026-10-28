<?php

namespace App\Http\Controllers\Front;

use App\Models\Game;
use App\Models\Player;
use App\Services\Leaderboards\Champion;
use App\Services\Leaderboards\Crowns;
use App\Services\Leaderboards\HeldCrown;
use Illuminate\Http\JsonResponse;

/** Champions and unbeaten first places, next to the global podium (docs/DECISIONS.md D96). */
final class CrownsController
{
    /** Entries of each list: the steps of a podium. */
    private const SHOWN = 3;

    public function __invoke(Crowns $crowns): JsonResponse
    {
        $champions = $crowns->champions(self::SHOWN);
        $unbeaten = $crowns->unbeaten(self::SHOWN);
        $players = Player::query()
            ->whereIn('id', $champions->map(fn (Champion $champion): int => $champion->playerId)
                ->merge($unbeaten->map(fn (HeldCrown $crown): int => $crown->playerId)))
            ->get()->keyBy('id');
        $games = Game::query()->whereIn('id', $unbeaten->map(fn (HeldCrown $crown): int => $crown->gameId))->get()->keyBy('id');

        return new JsonResponse([
            'champions' => $champions->map(fn (Champion $champion): array => [
                'rank' => $champion->rank,
                'player' => GamesController::player($players->get($champion->playerId)),
                'beaten' => $champion->beaten,
                'crowns' => $champion->crowns,
            ])->all(),
            'unbeaten' => $unbeaten->map(fn (HeldCrown $crown): array => [
                'rank' => $crown->rank,
                'player' => GamesController::player($players->get($crown->playerId)),
                'game' => ['romname' => (string) $games->get($crown->gameId)?->romname, 'description' => (string) $games->get($crown->gameId)?->description],
                'score' => $crown->score,
                'players' => $crown->players,
                'since' => $crown->since,
                'days' => $crown->days,
            ])->all(),
        ]);
    }
}
