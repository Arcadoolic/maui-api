<?php

namespace App\Http\Controllers\Front;

use App\Http\Middleware\AuthenticateMember;
use App\Http\Problems\ApiProblemException;
use App\Http\Requests\Api\LinkPlayerRequest;
use App\Models\Player;
use App\Services\Players\PlayerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** The logged-in member and the players it linked with their PIN (docs/DECISIONS.md D66). */
final class MeController
{
    public function __construct(private readonly PlayerRegistry $registry) {}

    public function show(Request $request): JsonResponse
    {
        $member = AuthenticateMember::member($request);

        return new JsonResponse([
            'member' => $member->toApiArray(),
            'players' => $member->players()->orderBy('pseudo_3')->get()
                ->map(fn (Player $player): array => $player->toFrontArray())->all(),
        ]);
    }

    public function linkPlayer(LinkPlayerRequest $request): JsonResponse
    {
        $player = $this->registry->linkMember(
            AuthenticateMember::member($request),
            $request->string('pseudo_3')->toString(),
            $request->string('pin')->toString(),
        );

        return new JsonResponse(['player' => $player->toFrontArray()]);
    }

    public function unlinkPlayer(Request $request, string $player): Response
    {
        $member = AuthenticateMember::member($request);
        // The players of other members do not exist for this one.
        $linked = Str::isUuid($player) ? $member->players()->where('players.uuid', $player)->first() : null;

        $this->registry->unlinkMember($member, $linked ?? throw ApiProblemException::playerNotFound());

        return response()->noContent();
    }
}
