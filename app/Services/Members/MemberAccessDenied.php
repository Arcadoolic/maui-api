<?php

namespace App\Services\Members;

use RuntimeException;

/** Why a Discord account is not let in: the `error` the front is sent back with. */
final class MemberAccessDenied extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
