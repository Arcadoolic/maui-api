<?php

namespace App\Services\Invitations;

use App\Enums\ClientType;
use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates the single-use link given to a cabinet owner. The API token is not
 * generated here: it only exists once the link is claimed (docs/DECISIONS.md D15).
 */
final class InvitationIssuer
{
    private const TOKEN_LENGTH = 48;

    public function issue(Client $client, InvitationPurpose $purpose, ?User $createdBy = null): IssuedInvitation
    {
        if ($client->type !== ClientType::Maui) {
            throw new InvalidArgumentException('Service accounts receive their token directly, not an invitation.');
        }

        $plainToken = Str::random(self::TOKEN_LENGTH);

        $invitation = DB::transaction(function () use ($client, $purpose, $createdBy, $plainToken): Invitation {
            // Only the latest link stays usable (docs/DECISIONS.md D24).
            $client->invitations()->pending()->update(['expires_at' => now()]);

            $invitation = $client->invitations()->make([
                'purpose' => $purpose,
                'expires_at' => now()->addHours((int) config('maui.invitation_ttl_hours')),
                'created_by' => $createdBy?->getKey(),
            ]);
            $invitation->token_hash = Invitation::hashToken($plainToken);
            $invitation->save();

            return $invitation;
        });

        return new IssuedInvitation($invitation, $plainToken, route('invitations.show', $plainToken));
    }
}
