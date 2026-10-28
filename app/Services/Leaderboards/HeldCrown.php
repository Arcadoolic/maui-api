<?php

namespace App\Services\Leaderboards;

/** A first place where others are ranked, and since when it holds (docs/DECISIONS.md D96). */
final readonly class HeldCrown
{
    public function __construct(
        public int $rank,
        public int $gameId,
        public int $playerId,
        public int $score,
        /** Ranked players on the leaderboard, the holder included. */
        public int $players,
        /** ISO 8601: the holder's first score above the second best one. */
        public string $since,
        public int $days,
    ) {}
}
