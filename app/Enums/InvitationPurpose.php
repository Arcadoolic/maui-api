<?php

namespace App\Enums;

enum InvitationPurpose: string
{
    /** First credentials of a new cabinet. */
    case Initial = 'initial';

    /** Replaces the credentials of an existing cabinet (docs/DECISIONS.md D5). */
    case Renewal = 'renewal';
}
