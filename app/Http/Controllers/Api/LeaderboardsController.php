<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Problems\ApiProblemException;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Services\Leaderboards\Leaderboards;
use App\Services\Players\PlayerAvatars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

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
        $found = $this->shownPlayer($player);

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

    /**
     * The PNG of a public and active player, with its hash as ETag (D53). Also of a player of the
     * calling cabinet, public or not (D56): a cabinet shows its own players their picture.
     */
    public function avatar(Request $request, string $player): Response
    {
        $found = $this->ownPlayer($request, $player) ?? $this->shownPlayer($player);
        if (! PlayerAvatars::exists($found)) {
            throw ApiProblemException::avatarNotFound();
        }

        $response = Storage::disk(PlayerAvatars::DISK)->response(PlayerAvatars::path($found), null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache',
        ]);
        $response->setEtag((string) $found->avatar_hash);
        $response->isNotModified($request);

        return $response;
    }

    /** An active player linked to the calling cabinet, null otherwise. */
    private function ownPlayer(Request $request, string $id): ?Player
    {
        $found = Str::isUuid($id)
            ? AuthenticateCabinet::client($request)->players()->where('players.uuid', $id)->first()
            : null;

        return $found !== null && $found->isActive() ? $found : null;
    }

    /** Players the shared leaderboards show: public and active. */
    private function shownPlayer(string $id): Player
    {
        $found = Str::isUuid($id)
            ? Player::query()->where('uuid', $id)->where('is_public', true)->first()
            : null;
        if ($found === null || ! $found->isActive()) {
            throw ApiProblemException::playerNotFound();
        }

        return $found;
    }
}
