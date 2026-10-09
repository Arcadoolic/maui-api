<?php

namespace App\Services\Members;

/** What Discord tells about the account that logs in (scope `identify`). */
final readonly class DiscordUser
{
    public function __construct(
        public string $id,
        public string $username,
        public ?string $displayName = null,
        public ?string $avatar = null,
    ) {}
}
