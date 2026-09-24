<?php

namespace App\Services\Invitations;

use App\Models\Invitation;
use App\Services\ClientTokenIssuer;
use App\Support\ConfigurationString;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Consumes an invitation and issues the cabinet credentials (docs/PLAN.md 1.3).
 */
final class InvitationClaimer
{
    public function __construct(private readonly ClientTokenIssuer $tokenIssuer) {}

    /**
     * @return string The `MAUI1.` configuration string, to display once.
     *
     * @throws ModelNotFoundException<Invitation> Unknown invitation.
     * @throws InvitationUnavailable Expired or already claimed.
     */
    public function claim(string $plainToken, ?string $ip): string
    {
        return DB::transaction(function () use ($plainToken, $ip): string {
            // Row lock: two concurrent claims of the same link cannot both succeed.
            $invitation = Invitation::query()->forToken($plainToken)->lockForUpdate()->firstOrFail();

            if (! $invitation->isClaimable()) {
                throw new InvitationUnavailable(claimed: $invitation->isClaimed());
            }

            $client = $invitation->client;
            $token = $this->tokenIssuer->issue($client);
            $client->resetMachineBinding();

            $invitation->forceFill(['claimed_at' => now(), 'claimed_ip' => $ip])->save();

            return ConfigurationString::encode((string) config('app.url'), $client->public_key, $token);
        });
    }
}
