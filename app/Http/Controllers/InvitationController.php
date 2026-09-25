<?php

namespace App\Http\Controllers;

use App\Enums\InvitationPurpose;
use App\Models\Invitation;
use App\Services\ClientNameGenerator;
use App\Services\Invitations\InvitationClaimer;
use App\Services\Invitations\InvitationUnavailable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InvitationController
{
    /**
     * Shows the claim button. Never consumes the invitation: link previews and
     * email scanners follow GET links (docs/DECISIONS.md D15).
     */
    public function show(string $token): Response
    {
        $invitation = Invitation::query()->forToken($token)->with('client')->first();

        if ($invitation === null) {
            return $this->unavailable('invalid', Response::HTTP_NOT_FOUND);
        }

        if (! $invitation->isClaimable()) {
            return $this->unavailable($invitation->isClaimed() ? 'claimed' : 'expired', Response::HTTP_GONE);
        }

        return response()->view('invitations.show', [
            'cabinetName' => $invitation->client->name,
            'claimUrl' => route('invitations.claim', $token),
            'renameUrl' => self::canRename($invitation) ? route('invitations.rename', $token) : null,
        ]);
    }

    /**
     * Draws another cabinet name, as many times as the owner wants, until the
     * credentials are claimed (docs/DECISIONS.md D30).
     */
    public function rename(string $token, ClientNameGenerator $names): Response|RedirectResponse
    {
        $invitation = Invitation::query()->forToken($token)->with('client')->first();

        if ($invitation === null) {
            return $this->unavailable('invalid', Response::HTTP_NOT_FOUND);
        }

        if (! $invitation->isClaimable()) {
            return $this->unavailable($invitation->isClaimed() ? 'claimed' : 'expired', Response::HTTP_GONE);
        }

        abort_unless(self::canRename($invitation), Response::HTTP_FORBIDDEN);

        $invitation->client->update(['name' => $names->generate()]);

        return redirect()->route('invitations.show', $token, Response::HTTP_SEE_OTHER);
    }

    public function claim(Request $request, string $token, InvitationClaimer $claimer): Response
    {
        try {
            $configuration = $claimer->claim($token, $request->ip());
        } catch (ModelNotFoundException) {
            return $this->unavailable('invalid', Response::HTTP_NOT_FOUND);
        } catch (InvitationUnavailable $unavailable) {
            return $this->unavailable($unavailable->claimed ? 'claimed' : 'expired', Response::HTTP_GONE);
        }

        $invitation = Invitation::query()->forToken($token)->with('client')->firstOrFail();

        return response()->view('invitations.claimed', [
            'configuration' => $configuration,
            'cabinetName' => $invitation->client->name,
        ]);
    }

    /** A renewed cabinet keeps its name: only a new cabinet can choose one. */
    private static function canRename(Invitation $invitation): bool
    {
        return $invitation->purpose === InvitationPurpose::Initial;
    }

    private function unavailable(string $reason, int $status): Response
    {
        return response()->view('invitations.unavailable', ['reason' => $reason], $status);
    }
}
