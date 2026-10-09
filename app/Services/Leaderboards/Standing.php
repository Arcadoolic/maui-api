<?php

namespace App\Services\Leaderboards;

/** A player on the global podium (docs/DECISIONS.md D70). */
final readonly class Standing
{
    /**
     * @param  int  $formerPoints  By the former rule (500, 300, 50 for the first three of each game).
     * @param  int  $games  Games the player is ranked on.
     * @param  list<array{game_id: int, table: string, rank: int, players: int, points: int, counted: bool}>  $results
     *                                                                                                                  One per game, best first; `counted`: among those that make the total.
     */
    public function __construct(
        public int $playerId,
        public int $rank,
        public int $points,
        public int $formerPoints,
        public int $crowns,
        public int $podiums,
        public int $games,
        public array $results,
    ) {}

    public function counted(): int
    {
        return count(array_filter($this->results, fn (array $result): bool => $result['counted']));
    }
}
