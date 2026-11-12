<?php

namespace App\Http\Controllers\Api;

use App\Models\Game;
use App\Models\Player;
use App\Services\Leaderboards\Crowns;
use App\Services\Leaderboards\HeldCrown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The champions of the day (D96) for an idle cabinet, its champions screen (docs/DECISIONS.md
 * D101): with the players' avatars (D53) and the hashes of their games' pictures (D99), which the
 * cabinet downloads with `GET /games/{romname}/media/{type}` when it does not have the game.
 */
final class ChampionsController
{
    public const MAX_CHAMPIONS = 30;

    public const DEFAULT_CHAMPIONS = 10;

    /** The pictures a cabinet shows of a game. */
    private const MEDIA = ['marquee', 'flyer', 'logo'];

    public function __invoke(Request $request, Crowns $crowns): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_CHAMPIONS]]);
        $champions = $crowns->champions(now(), (int) ($validated['limit'] ?? self::DEFAULT_CHAMPIONS));
        $players = Player::query()->whereIn('id', $champions->map(fn (HeldCrown $crown): int => $crown->playerId))->get()->keyBy('id');
        $games = Game::query()->whereIn('id', $champions->map(fn (HeldCrown $crown): int => $crown->gameId))
            ->with(['media' => fn ($media) => $media->whereIn('type', self::MEDIA)->where('position', 0)])
            ->get()->keyBy('id');

        return new JsonResponse([
            'champions' => $champions->map(function (HeldCrown $crown) use ($players, $games): array {
                $player = $players->get($crown->playerId);
                $game = $games->get($crown->gameId);
                $media = $game?->media->keyBy('type');

                return [
                    'player' => [
                        'id' => (string) $player?->uuid,
                        'pseudo_3' => (string) $player?->pseudo_3,
                        'avatar' => $player?->avatar_hash,
                    ],
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
            })->values()->all(),
        ]);
    }
}
