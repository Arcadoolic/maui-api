<?php

namespace App\Services\Leaderboards;

/** A game a single player is ranked on, and the score to beat there (docs/DECISIONS.md D95). */
final readonly class Challenge
{
    public function __construct(
        public int $gameId,
        public int $playerId,
        public int $score,
        /** When the score to beat was made. */
        public string $since,
    ) {}
}
