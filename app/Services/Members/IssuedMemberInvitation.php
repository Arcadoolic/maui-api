<?php

namespace App\Services\Members;

use App\Models\MemberInvitation;

/** A new invitation with its link, known only now: the token is stored hashed. */
final readonly class IssuedMemberInvitation
{
    public function __construct(
        public MemberInvitation $invitation,
        public string $plainToken,
        public string $url,
    ) {}
}
