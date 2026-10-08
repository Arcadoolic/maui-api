<?php

namespace App\Services\Scores;

/**
 * What a score changed on its leaderboard, and what the history says of it:
 * the facts a score event is classified from, stored with it (docs/DECISIONS.md D60).
 *
 * @phpstan-type Rival array{id: string, pseudo_3: string, score: int, cabinet: string}
 */
final readonly class ScoreFacts
{
    /**
     * @param  int|null  $previousBest  Null on the player's first score on the game.
     * @param  Rival|null  $displaced  The player who held `rankAfter` before this score.
     * @param  Rival|null  $ahead  The player right above after this score.
     * @param  Rival|null  $previousLeader  The first of the leaderboard before this score, when another player.
     * @param  int  $overtaken  Players passed by this score.
     * @param  bool  $away  Made on another cabinet than the displaced player's best.
     * @param  string|null  $pushedOffPodium  Initials of the player this score takes out of the top 3.
     * @param  string|null  $pushedOutOfBoard  Initials of the player this score takes out of the rows a cabinet shows.
     * @param  bool  $heldFirst  The player led this leaderboard before.
     * @param  bool  $staircase  The player was third, then second, on this leaderboard.
     * @param  bool  $revenge  The displaced player was the last to take a place from this player.
     * @param  int  $rounds  Times the two players took this place from each other, this one included.
     * @param  int|null  $reignDays  Days the dethroned leader held the first place.
     * @param  int  $streak  Personal bests of the player on the game in the last days, this one included.
     * @param  int|null  $awayDays  Days since the player's previous best on the game.
     * @param  int  $games  Games the player has a score on, this one included.
     * @param  bool  $newGame  First score of the player on this game, all tables together.
     * @param  int|null  $crowns  Leaderboards the player leads, when this score adds one.
     * @param  int|null  $milestone  Round score no one had reached on this leaderboard.
     */
    public function __construct(
        public int $score,
        public ?int $previousBest,
        public ?int $rankBefore,
        public int $rankAfter,
        public int $boardSize,
        public ?array $displaced,
        public ?array $ahead,
        public ?array $previousLeader,
        public int $overtaken,
        public string $cabinet,
        public bool $away,
        public ?string $pushedOffPodium,
        public ?string $pushedOutOfBoard,
        public bool $heldFirst,
        public bool $staircase,
        public bool $revenge,
        public int $rounds,
        public ?int $reignDays,
        public int $streak,
        public ?int $awayDays,
        public bool $firstScoreEver,
        public int $games,
        public bool $newGame,
        public ?int $crowns,
        public ?int $milestone,
    ) {}

    /** Points to the player right above. */
    public function gap(): ?int
    {
        return $this->ahead === null ? null : $this->ahead['score'] - $this->score;
    }

    /** Points over the displaced player. */
    public function margin(): ?int
    {
        return $this->displaced === null ? null : $this->score - $this->displaced['score'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'previous_best' => $this->previousBest,
            'rank_before' => $this->rankBefore,
            'rank_after' => $this->rankAfter,
            'board_size' => $this->boardSize,
            'displaced' => $this->displaced,
            'ahead' => $this->ahead,
            'gap' => $this->gap(),
            'previous_leader' => $this->previousLeader,
            'overtaken' => $this->overtaken,
            'cabinet' => $this->cabinet,
            'away' => $this->away,
            'pushed_off_podium' => $this->pushedOffPodium,
            'pushed_out_of_board' => $this->pushedOutOfBoard,
            'held_first' => $this->heldFirst,
            'staircase' => $this->staircase,
            'revenge' => $this->revenge,
            'rounds' => $this->rounds,
            'reign_days' => $this->reignDays,
            'streak' => $this->streak,
            'away_days' => $this->awayDays,
            'first_score_ever' => $this->firstScoreEver,
            'games' => $this->games,
            'new_game' => $this->newGame,
            'crowns' => $this->crowns,
            'milestone' => $this->milestone,
        ];
    }
}
