<?php

namespace App\Services\Scores;

use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Services\Leaderboards\Leaderboards;

/**
 * Writes the sentence of a score event, in English and Markdown: one of the
 * variants of its movement, the closing line of its first flavor, then the
 * player it pushed off the podium or out of the leaderboard. The variant
 * comes from the event's id: the same event always reads the same
 * (docs/DECISIONS.md D60).
 */
final class ScoreEventMessage
{
    /** @var array<string, list<string>> */
    public const MOVEMENTS = [
        ScoreSituation::OPENS_BOARD => [
            '**:player** opens the board on :game with **:score**. Who\'s next?',
            'First blood on :game: **:player** sets the mark at **:score**.',
            ':game has its first challenger: **:player** posts **:score**.',
        ],
        ScoreSituation::DEBUT_FIRST => [
            '**:player** storms into :game and takes 1st place from **:displaced** at the first attempt with **:score**!',
            'What an entrance! **:player** debuts on :game straight at the top with **:score**, knocking **:displaced** off 1st place.',
        ],
        ScoreSituation::DEBUT_PODIUM => [
            '**:player** enters the arena on :game, straight onto the podium in :rank place with **:score**.',
            '**:player** joins the fight on :game and lands directly in :rank place with **:score**.',
        ],
        ScoreSituation::DEBUT => [
            '**:player** joins the fight on :game, straight into :rank place with **:score**.',
            '**:player** enters the arena on :game, landing in :rank place with **:score**.',
            'New challenger on :game: **:player** starts in :rank place with **:score**.',
        ],
        ScoreSituation::RECLAIMS_FIRST => [
            '**:player** takes back 1st place on :game from **:displaced** with **:score**.',
            '**:player** is back on top of :game, reclaiming 1st place from **:displaced** with **:score**.',
        ],
        ScoreSituation::TAKES_FIRST => [
            '**:player** snatches 1st place from **:displaced** on :game with **:score**.',
            'New leader on :game: **:player** dethrones **:displaced** with **:score**.',
        ],
        ScoreSituation::EXTENDS_LEAD => [
            '**:player** tightens their grip on :game: the record now stands at **:score**.',
            '**:player** raises the bar on :game: **:score** is the new score to beat.',
        ],
        ScoreSituation::ENTERS_PODIUM => [
            '**:player** climbs onto the podium on :game, taking :rank place from **:displaced** with **:score**.',
            '**:player** breaks into the top 3 on :game: :rank place, ahead of **:displaced**, with **:score**.',
        ],
        ScoreSituation::CLIMBS => [
            '**:player** takes :rank place from **:displaced** on :game with **:score**.',
            '**:player** moves up to :rank place on :game with **:score**, overtaking **:displaced**.',
        ],
        ScoreSituation::IMPROVES => [
            '**:player** improves to **:score** on :game, still :rank, now **:gap** behind **:ahead**.',
            '**:player** pushes their best to **:score** on :game: still :rank, **:gap** short of **:ahead**.',
        ],
    ];

    /** @var array<string, list<string>> */
    public const FLAVORS = [
        'staircase' => ['Is anyone able to stop this meteoric rise?', 'Step by step, all the way to the top!'],
        'rivalry' => ['The duel goes on: round :rounds!'],
        'revenge' => ['Payback time.', 'Revenge is served.'],
        'reign_ended' => ['A :reign-day reign comes to an end.'],
        'photo_finish' => ['By just :margin!'],
        'crushing' => ['A crushing margin.', 'Not even close.'],
        'huge_jump' => ['Huge leap forward!', 'More than double their previous best!'],
        'leapfrog' => ['Jumping over :overtaken players at once.'],
        'on_a_roll' => ['Nothing can stop them now.', 'That\'s :streak personal bests in a week.'],
        'comeback' => ['Back after :away away.'],
        'milestone' => ['First player past :milestone!'],
        'newcomer' => ['Welcome to the leaderboards!'],
        'multi_crown' => ['Now reigning over :crowns games.'],
        'collector' => ['That\'s their :games game on the boards.'],
        'away_win' => ['Scored on :cabinet.'],
    ];

    private const PUSHED_OFF_PODIUM = '**:victim** drops off the podium.';

    private const PUSHED_OUT_OF_BOARD = '**:victim** is out of the top :size.';

    /**
     * @param  string  $seed  The event's id: picks the variants.
     * @param  list<string>  $flavors
     */
    public function compose(string $seed, string $movement, array $flavors, ScoreFacts $facts, Player $player, Game $game, string $table): string
    {
        $values = $this->values($facts, $player, $game, $table);
        $sentences = [$this->pick(self::MOVEMENTS[$movement], $seed)];
        if ($flavors !== []) {
            $sentences[] = $this->pick(self::FLAVORS[$flavors[0]], $seed.$flavors[0]);
        }
        if ($facts->pushedOffPodium !== null) {
            $sentences[] = strtr(self::PUSHED_OFF_PODIUM, [':victim' => $facts->pushedOffPodium]);
        } elseif ($facts->pushedOutOfBoard !== null) {
            $sentences[] = strtr(self::PUSHED_OUT_OF_BOARD, [':victim' => $facts->pushedOutOfBoard]);
        }

        return strtr(implode(' ', $sentences), $values);
    }

    /**
     * @return array<string, string>
     */
    private function values(ScoreFacts $facts, Player $player, Game $game, string $table): array
    {
        $margin = (int) $facts->margin();

        return [
            ':player' => $player->pseudo_3,
            ':game' => $this->game($game, $table),
            ':score' => number_format($facts->score),
            ':rank' => self::ordinal($facts->rankAfter),
            ':displaced' => $facts->displaced['pseudo_3'] ?? '',
            ':ahead' => $facts->ahead['pseudo_3'] ?? '',
            ':gap' => number_format((int) $facts->gap()),
            ':margin' => number_format($margin).($margin === 1 ? ' point' : ' points'),
            ':rounds' => (string) $facts->rounds,
            ':reign' => number_format((int) $facts->reignDays),
            ':overtaken' => (string) $facts->overtaken,
            ':streak' => (string) $facts->streak,
            ':away' => self::duration((int) $facts->awayDays),
            ':milestone' => number_format((int) $facts->milestone),
            ':crowns' => (string) $facts->crowns,
            ':games' => self::ordinal($facts->games),
            ':cabinet' => self::escape($facts->cabinet),
            ':size' => (string) Leaderboards::SIZE,
        ];
    }

    /** "**Galaga (Rev. 1)** by Namco", with the table when it is not the default one. */
    private function game(Game $game, string $table): string
    {
        return '**'.self::escape($game->description).'**'
            .($table === Score::DEFAULT_TABLE ? '' : ' (table '.self::escape($table).')')
            .($game->manufacturer === null || $game->manufacturer === '' ? '' : ' by '.self::escape($game->manufacturer));
    }

    /**
     * @param  list<string>  $variants
     */
    private function pick(array $variants, string $seed): string
    {
        return $variants[crc32($seed) % count($variants)];
    }

    public static function ordinal(int $number): string
    {
        $suffix = match (true) {
            in_array($number % 100, [11, 12, 13], true) => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return number_format($number).$suffix;
    }

    private static function duration(int $days): string
    {
        return match (true) {
            $days < 60 => $days.' days',
            $days < 730 => intdiv($days, 30).' months',
            default => intdiv($days, 365).' years',
        };
    }

    /** Game and cabinet names are plain text: their Markdown characters are escaped. */
    private static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\*_~`|])/', '\\\\$1', $text);
    }
}
