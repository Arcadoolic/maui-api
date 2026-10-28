<?php

namespace App\Http\Controllers\Front;

use App\Models\Game;
use App\Models\Player;
use App\Services\Leaderboards\Crowns;
use App\Services\Leaderboards\HeldCrown;
use Illuminate\Http\JsonResponse;

/** Champions: first places and their games, next to the global podium (docs/DECISIONS.md D96). */
final class CrownsController
{
    /** Cards of the carousel. */
    private const SHOWN = 6;

    /** The pictures of a card: the cabinet's marquee, else its flyer and logo; the screenshot behind. */
    private const MEDIA = ['marquee', 'flyer', 'logo', 'screenshot'];

    public function __invoke(Crowns $crowns): JsonResponse
    {
        $champions = $crowns->champions(now(), self::SHOWN);
        $players = Player::query()->whereIn('id', $champions->map(fn (HeldCrown $crown): int => $crown->playerId))->get()->keyBy('id');
        $games = Game::query()->whereIn('id', $champions->map(fn (HeldCrown $crown): int => $crown->gameId))
            ->with(['media' => fn ($media) => $media->whereIn('type', self::MEDIA)->where('position', 0)])
            ->get()->keyBy('id');

        return new JsonResponse([
            'champions' => $champions->map(function (HeldCrown $crown) use ($players, $games): array {
                $game = $games->get($crown->gameId);
                $media = $game?->media->keyBy('type');

                return [
                    'player' => GamesController::player($players->get($crown->playerId)),
                    'game' => [
                        'romname' => (string) $game?->romname,
                        'description' => (string) $game?->description,
                        ...array_combine(self::MEDIA, array_map(fn (string $type): ?string => $media?->get($type)?->hash, self::MEDIA)),
                    ],
                    'score' => $crown->score,
                    'players' => $crown->players,
                    'since' => $crown->since,
                    'days' => $crown->days,
                ];
            })->all(),
        ]);
    }
}
