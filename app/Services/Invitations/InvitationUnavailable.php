<?php

namespace App\Services\Invitations;

use RuntimeException;

/**
 * The invitation exists but can no longer be claimed.
 */
final class InvitationUnavailable extends RuntimeException
{
    public function __construct(public readonly bool $claimed)
    {
        parent::__construct($claimed ? 'Invitation already claimed.' : 'Invitation expired.');
    }
}
