<?php

namespace App\Services\Members;

use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Front;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Creates the link an admin gives to future members of the front
 * (docs/DECISIONS.md D65). It opens a page of the front, which sends the
 * visitor to Discord with the token.
 */
final class MemberInvitationIssuer
{
    public const TOKEN_LENGTH = 48;

    /**
     * @param  int|null  $maxUses  Null: any number of accounts.
     * @param  DateTimeInterface|null  $expiresAt  Null: until it is revoked.
     */
    public function issue(string $label, ?int $maxUses = 1, ?DateTimeInterface $expiresAt = null, ?User $createdBy = null): IssuedMemberInvitation
    {
        $plainToken = Str::random(self::TOKEN_LENGTH);

        $invitation = new MemberInvitation([
            'label' => $label,
            'max_uses' => $maxUses,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy?->getKey(),
        ]);
        $invitation->token_hash = MemberInvitation::hashToken($plainToken);
        $invitation->save();

        return new IssuedMemberInvitation($invitation, $plainToken, Front::url('/invite/'.$plainToken));
    }
}
