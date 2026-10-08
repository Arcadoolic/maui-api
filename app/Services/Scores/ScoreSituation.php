<?php

namespace App\Services\Scores;

use App\Services\Leaderboards\Leaderboards;

/**
 * Classifies a score event: one movement, what the score did on its
 * leaderboard, and any number of flavors, what makes it remarkable
 * (docs/DECISIONS.md D60). Thresholds live in `config/hiscores.php`.
 */
final class ScoreSituation
{
    public const OPENS_BOARD = 'opens_board';

    public const DEBUT_FIRST = 'debut_first';

    public const DEBUT_PODIUM = 'debut_podium';

    public const DEBUT = 'debut';

    public const RECLAIMS_FIRST = 'reclaims_first';

    public const TAKES_FIRST = 'takes_first';

    public const EXTENDS_LEAD = 'extends_lead';

    public const ENTERS_PODIUM = 'enters_podium';

    public const CLIMBS = 'climbs';

    public const IMPROVES = 'improves';

    public const PODIUM = 3;

    /** Movements told whatever the rank: a first score, a new leader, a new podium. */
    private const HEADLINES = [
        self::OPENS_BOARD, self::DEBUT_FIRST, self::DEBUT_PODIUM, self::DEBUT,
        self::RECLAIMS_FIRST, self::TAKES_FIRST, self::ENTERS_PODIUM,
    ];

    /** The first that matches. */
    public function movement(ScoreFacts $facts): string
    {
        $before = $facts->rankBefore;
        $after = $facts->rankAfter;

        return match (true) {
            $facts->boardSize === 1 && $before === null => self::OPENS_BOARD,
            $before === null && $after === 1 => self::DEBUT_FIRST,
            // A podium only means something once a player stands below it.
            $before === null && $after <= self::PODIUM && $facts->boardSize > self::PODIUM => self::DEBUT_PODIUM,
            $before === null => self::DEBUT,
            $after === 1 && $before > 1 => $facts->heldFirst ? self::RECLAIMS_FIRST : self::TAKES_FIRST,
            $before === 1 => self::EXTENDS_LEAD,
            $after <= self::PODIUM && $before > self::PODIUM => self::ENTERS_PODIUM,
            $after < $before => self::CLIMBS,
            default => self::IMPROVES,
        };
    }

    /**
     * Every flavor the score earns, the most remarkable first: the first one
     * gives the message its closing line.
     *
     * @return list<string>
     */
    public function flavors(ScoreFacts $facts, string $movement): array
    {
        $becomesLeader = $facts->rankAfter === 1 && $facts->rankBefore !== 1;
        $margin = $facts->margin();
        $leader = $facts->previousLeader;

        return array_keys(array_filter([
            'staircase' => $movement === self::TAKES_FIRST && $facts->staircase,
            'rivalry' => $facts->rounds >= $this->threshold('rivalry_rounds'),
            'revenge' => $facts->revenge,
            'reign_ended' => $facts->reignDays !== null && $facts->reignDays >= $this->threshold('reign_days'),
            'photo_finish' => $facts->displaced !== null && $margin !== null
                && $margin * 100 < $facts->displaced['score'] * $this->threshold('photo_finish_percent'),
            'crushing' => $becomesLeader && $leader !== null
                && $facts->score * 100 >= $leader['score'] * (100 + $this->threshold('crushing_percent')),
            'huge_jump' => $facts->previousBest !== null && $facts->previousBest > 0
                && $facts->score >= $facts->previousBest * $this->threshold('huge_jump_factor'),
            'leapfrog' => $facts->rankBefore !== null && $facts->overtaken >= $this->threshold('leapfrog_players'),
            // Every third best, not each one after the third: a long session is told once in a while.
            'on_a_roll' => $facts->streak > 0 && $facts->streak % $this->threshold('roll_scores') === 0,
            'comeback' => $facts->awayDays !== null && $facts->awayDays >= $this->threshold('comeback_days'),
            'milestone' => $facts->milestone !== null,
            'newcomer' => $facts->firstScoreEver,
            'multi_crown' => $facts->crowns !== null && in_array($facts->crowns, (array) config('hiscores.events.crowns'), true),
            'collector' => $facts->newGame && in_array($facts->games, (array) config('hiscores.events.games'), true),
            'away_win' => $facts->displaced !== null && $facts->away,
        ]));
    }

    /**
     * 3: a first score, a new leader, the podium. 2: the rows a cabinet
     * shows, or any flavor. 1: the rest. A reader may filter on it.
     *
     * @param  list<string>  $flavors
     */
    public function importance(ScoreFacts $facts, string $movement, array $flavors): int
    {
        return match (true) {
            in_array($movement, self::HEADLINES, true), $facts->rankAfter <= self::PODIUM => 3,
            $facts->rankAfter <= Leaderboards::SIZE, $flavors !== [] => 2,
            default => 1,
        };
    }

    private function threshold(string $name): int
    {
        return (int) config('hiscores.events.'.$name);
    }
}
