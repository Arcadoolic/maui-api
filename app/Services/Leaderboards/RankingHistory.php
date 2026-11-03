<?php

namespace App\Services\Leaderboards;

use Illuminate\Support\Carbon;

/**
 * The places of the global podium's first players, day by day (docs/DECISIONS.md
 * D97). No history of the ranking is kept: it is played again from the scores,
 * as it stood at the end of each day a visible score was made, with today's
 * rules and moderation.
 */
final class RankingHistory
{
    /** Players on the chart: past ten lines, the colours no longer tell them apart (D91). */
    public const PLAYERS = 10;

    public function __construct(private readonly GlobalRanking $ranking) {}

    public static function timezone(): string
    {
        return (string) config('hiscores.ranking.history_timezone');
    }

    /**
     * The first players of today, first to last, each with its places: one on
     * each day it changed, from the day it entered the ranking.
     *
     * @return list<array{player_id: int, rank: int, history: list<array{date: string, rank: int}>}>
     */
    public function top(): array
    {
        $top = $this->ranking->standings()->take(self::PLAYERS);
        if ($top->isEmpty()) {
            return [];
        }

        $timezone = self::timezone();
        $days = Leaderboards::visibleScores()->toBase()
            ->selectRaw('distinct (scores.achieved_at at time zone ?)::date as day', [$timezone])
            ->orderBy('day')
            ->pluck('day');

        $histories = [];
        foreach ($days as $day) {
            $end = Carbon::parse((string) $day, $timezone)->addDay();
            $ranks = $this->ranking->standings(before: $end)->mapWithKeys(fn (Standing $standing): array => [$standing->playerId => $standing->rank]);
            foreach ($top as $standing) {
                $rank = $ranks->get($standing->playerId);
                $last = $histories[$standing->playerId] ?? [];
                if ($rank !== null && $rank !== (end($last)['rank'] ?? null)) {
                    $histories[$standing->playerId][] = ['date' => (string) $day, 'rank' => $rank];
                }
            }
        }

        return array_values($top->map(fn (Standing $standing): array => [
            'player_id' => $standing->playerId,
            'rank' => $standing->rank,
            'history' => $histories[$standing->playerId] ?? [],
        ])->all());
    }
}
