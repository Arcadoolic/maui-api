<?php

namespace App\Services\Invitations;

use App\Models\Invitation;

final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        /** Only available right after creation: never stored. */
        public string $plainToken,
        public string $url,
    ) {}
}
