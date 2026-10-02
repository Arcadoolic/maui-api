<?php

namespace App\Http\Controllers\Api;

use App\Http\Problems\ApiProblemException;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Services\Leaderboards\Leaderboards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Shared leaderboards, for the cabinets (docs/DECISIONS.md D52). */
final class LeaderboardsController
{
    public const MAX_ROMNAMES = 100;

    /** MAME short names, as in the catalog and the scores. */
    private const ROMNAME = '/^[a-z0-9_]{1,32}$/';

    public function __construct(private readonly Leaderboards $leaderboards) {}

    /** Several games at once: a cabinet refreshes the leaderboards of all its games. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'romnames' => ['required', 'string'],
            'table' => ['sometimes', 'string', 'regex:'.self::ROMNAME],
        ]);
        $romnames = array_values(array_unique(array_filter(explode(',', $validated['romnames']))));
        $request->merge(['romname_list' => $romnames])->validate([
            'romname_list' => ['list', 'min:1', 'max:'.self::MAX_ROMNAMES],
            'romname_list.*' => ['regex:'.self::ROMNAME],
        ]);
        sort($romnames);
        $table = $validated['table'] ?? Score::DEFAULT_TABLE;
        $games = Game::query()->whereIn('romname', $romnames)->get()->keyBy('romname');

        return new JsonResponse(['leaderboards' => array_map(
            fn (string $romname): array => $this->leaderboards->toApiArray($romname, $games->get($romname), $table),
            $romnames,
        )]);
    }

    public function show(Request $request, string $romname): JsonResponse
    {
        $table = $request->validate(['table' => ['sometimes', 'string', 'regex:'.self::ROMNAME]])['table'] ?? Score::DEFAULT_TABLE;
        $game = preg_match(self::ROMNAME, $romname) === 1 ? Game::query()->where('romname', $romname)->first() : null;

        return new JsonResponse($this->leaderboards->toApiArray($romname, $game, $table));
    }

    /** Any public and active player: what the shared leaderboards show of it. */
    public function bests(string $player): JsonResponse
    {
        $found = Str::isUuid($player)
            ? Player::query()->where('uuid', $player)->where('is_public', true)->first()
            : null;
        if ($found === null || ! $found->isActive()) {
            throw ApiProblemException::playerNotFound();
        }

        return new JsonResponse([
            'player' => ['id' => $found->uuid, 'pseudo_3' => $found->pseudo_3],
            'bests' => $this->leaderboards->bests($found)->map(fn (Score $score): array => [
                'romname' => $score->game->romname,
                'table' => $score->table,
                'score' => $score->score,
                'achieved_at' => $score->achieved_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
