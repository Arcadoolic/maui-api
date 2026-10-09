<?php

namespace App\Http\Controllers\Front;

use App\Http\Middleware\AuthenticateMember;
use App\Http\Problems\ApiProblemException;
use App\Http\Requests\Api\LinkPlayerRequest;
use App\Services\Players\PlayerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The logged-in member and its player, linked with the PIN (docs/DECISIONS.md D66, D71). */
final class MeController
{
    public function __construct(private readonly PlayerRegistry $registry) {}

    public function show(Request $request): JsonResponse
    {
        $member = AuthenticateMember::member($request);

        return new JsonResponse([
            'member' => $member->toApiArray(),
            'player' => $member->player()?->toFrontArray(),
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

    public function unlinkPlayer(Request $request): Response
    {
        $member = AuthenticateMember::member($request);

        $this->registry->unlinkMember($member, $member->player() ?? throw ApiProblemException::playerNotFound());

        return response()->noContent();
    }
}
