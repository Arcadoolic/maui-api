<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Problems\ApiProblemException;
use App\Http\Requests\Api\LinkPlayerRequest;
use App\Http\Requests\Api\StoreAvatarRequest;
use App\Http\Requests\Api\StorePlayerRequest;
use App\Http\Requests\Api\UpdatePlayerRequest;
use App\Models\Player;
use App\Services\Players\PlayerAvatars;
use App\Services\Players\PlayerRegistry;
use App\Support\Pseudo3;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Players of the calling cabinet (docs/DECISIONS.md D48). */
final class PlayersController
{
    public function __construct(private readonly PlayerRegistry $registry) {}

    public function index(Request $request): JsonResponse
    {
        $players = AuthenticateCabinet::client($request)->players()->orderBy('pseudo_3')->get();

        return new JsonResponse(['players' => $players->map(fn (Player $player): array => $player->toApiArray())->all()]);
    }

    public function availability(Request $request): JsonResponse
    {
        $pseudo3 = $request->validate(['pseudo_3' => Pseudo3::rules()])['pseudo_3'];

        return new JsonResponse(['pseudo_3' => $pseudo3, 'availability' => $this->registry->availability($pseudo3)]);
    }

    public function store(StorePlayerRequest $request): JsonResponse
    {
        [$player, $pin] = $this->registry->register(
            AuthenticateCabinet::client($request),
            $request->string('pseudo_3')->toString(),
            $request->boolean('is_public'),
        );

        return new JsonResponse(['player' => $player->toApiArray(), 'pin' => $pin], JsonResponse::HTTP_CREATED);
    }

    public function link(LinkPlayerRequest $request): JsonResponse
    {
        $player = $this->registry->link(
            AuthenticateCabinet::client($request),
            $request->string('pseudo_3')->toString(),
            $request->string('pin')->toString(),
        );

        return new JsonResponse(['player' => $player->toApiArray()]);
    }

    public function update(UpdatePlayerRequest $request, string $player): JsonResponse
    {
        $updated = $this->registry->setVisibility(
            AuthenticateCabinet::client($request),
            $this->linkedPlayer($request, $player),
            $request->boolean('is_public'),
        );

        return new JsonResponse(['player' => $updated->toApiArray()]);
    }

    public function regeneratePin(Request $request, string $player): JsonResponse
    {
        $pin = $this->registry->regeneratePin(AuthenticateCabinet::client($request), $this->linkedPlayer($request, $player));

        return new JsonResponse(['pin' => $pin]);
    }

    public function unlink(Request $request, string $player): Response
    {
        $this->registry->unlink(AuthenticateCabinet::client($request), $this->linkedPlayer($request, $player));

        return response()->noContent();
    }

    /** The avatar of a player of this cabinet (D53). */
    public function storeAvatar(StoreAvatarRequest $request, PlayerAvatars $avatars, string $player): JsonResponse
    {
        $linked = $this->linkedPlayer($request, $player);
        $file = $request->file('avatar');
        assert($file instanceof UploadedFile);
        $avatars->store(AuthenticateCabinet::client($request), $linked, $file);

        return new JsonResponse(['player' => $linked->toApiArray(), 'avatar' => $linked->avatar_hash]);
    }

    /** The players of other cabinets do not exist for this one. */
    private function linkedPlayer(Request $request, string $id): Player
    {
        $player = Str::isUuid($id)
            ? AuthenticateCabinet::client($request)->players()->where('players.uuid', $id)->first()
            : null;

        return $player ?? throw ApiProblemException::playerNotFound();
    }
}
