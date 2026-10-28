<?php

namespace App\Services\Leaderboards;

/** A player by the first places they hold where others are ranked (docs/DECISIONS.md D96). */
final readonly class Champion
{
    public function __construct(
        public int $rank,
        public int $playerId,
        /** The players ranked under them, all their first places together. */
        public int $beaten,
        public int $crowns,
    ) {}
}
