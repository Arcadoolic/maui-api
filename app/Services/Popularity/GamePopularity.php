<?php

namespace App\Services\Popularity;

use App\Enums\PopularityLabel;

/** One game's popularity, and what it was computed from (docs/DECISIONS.md D76). */
final readonly class GamePopularity
{
    public function __construct(
        public int $gameId,
        public int $thumbsUp,
        public int $thumbsDown,
        /** Cabinets that voted or played. */
        public int $cabinets,
        /** Games started, all cabinets together. */
        public int $plays,
        /** Cabinets that played it within the recent days. */
        public int $recentCabinets,
        public int $rankedPlayers,
        /** 0 to 1: the share of thumbs up, drawn to the fleet's average while the votes are few. */
        public float $opinion,
        /** 0 to 1: how much it is played. */
        public float $activity,
        /** 0 to 100: what the games are sorted by. */
        public float $index,
        public ?PopularityLabel $label,
    ) {}

    public function votes(): int
    {
        return $this->thumbsUp + $this->thumbsDown;
    }
}
