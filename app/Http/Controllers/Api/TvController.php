<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlayerStatus;
use App\Http\Controllers\Front\GamesController;
use App\Http\Controllers\Front\PlayersController as FrontPlayersController;
use App\Http\Middleware\AuthenticateService;
use App\Http\Problems\ApiProblemException;
use App\Http\Requests\Api\LinkPlayerRequest;
use App\Models\Player;
use App\Services\Players\PlayerAvatars;
use App\Services\Players\PlayerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The players of a TV app (docs/DECISIONS.md D102): linked with their
 * initials and PIN, as on a cabinet, and shown as the hiscores front shows
 * a member's own player. The global podium and the events are the cabinets'
 * and the bots' (RankingController, ScoreEventsController).
 */
final class TvController
{
    public function __construct(
        private readonly PlayerRegistry $registry,
        private readonly FrontPlayersController $pages,
    ) {}

    /** The TV's players, with their totals and place on the global podium. */
    public function players(Request $request): JsonResponse
    {
        $players = AuthenticateService::client($request)->tvPlayers()
            ->where('players.status', PlayerStatus::Active)
            ->orderBy('tv_players.linked_at')
            ->get();

        return new JsonResponse([
            'players' => $players->map(fn (Player $player): array => [
                'player' => [...GamesController::player($player), 'is_public' => $player->is_public],
                'stats' => $this->pages->page($player, true)['stats'],
            ])->all(),
        ]);
    }

    public function link(LinkPlayerRequest $request): JsonResponse
    {
        $player = $this->registry->linkTv(
            AuthenticateService::client($request),
            $request->string('pseudo_3')->toString(),
            $request->string('pin')->toString(),
        );

        return new JsonResponse(['player' => [...GamesController::player($player), 'is_public' => $player->is_public]]);
    }

    public function unlink(Request $request, string $player): Response
    {
        $this->registry->unlinkTv(AuthenticateService::client($request), $this->ownPlayer($request, $player));

        return response()->noContent();
    }

    public function show(Request $request, string $player): JsonResponse
    {
        return new JsonResponse($this->pages->page($this->ownPlayer($request, $player), true));
    }

    /** The avatar of a player the podium or the events show (public), or of the TV's own. */
    public function avatar(Request $request, string $player): Response
    {
        $found = Str::isUuid($player) ? Player::query()->where('uuid', Str::lower($player))->first() : null;
        if ($found === null || ! $found->isActive()
            || (! $found->is_public && ! AuthenticateService::client($request)->tvPlayers()->whereKey($found->id)->exists())) {
            throw ApiProblemException::playerNotFound();
        }
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

    /** An active player linked to the calling TV. */
    private function ownPlayer(Request $request, string $id): Player
    {
        $found = Str::isUuid($id)
            ? AuthenticateService::client($request)->tvPlayers()->where('players.uuid', Str::lower($id))->first()
            : null;
        if ($found === null || ! $found->isActive()) {
            throw ApiProblemException::playerNotFound();
        }

        return $found;
    }
}
