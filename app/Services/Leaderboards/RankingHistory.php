<?php

namespace App\Services\Leaderboards;

use Illuminate\Support\Carbon;

/**
 * The places of the global podium's first players, day by day (docs/DECISIONS.md
 * D97). No history of the ranking is kept: it is played again from the scores,
 * as it stood at the end of each day a visible score was made, with today's
 * rules and moderation. The points too, on each day they changed (D104).
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
     * The first players of today, first to last, each with its places and its
     * points: one on each day it changed, from the day it entered the ranking.
     *
     * @return list<array{player_id: int, rank: int, points: int, history: list<array{date: string, rank: int}>, points_history: list<array{date: string, points: int}>}>
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
        $pointsHistories = [];
        foreach ($days as $day) {
            $end = Carbon::parse((string) $day, $timezone)->addDay();
            $standings = $this->ranking->standings(before: $end)->keyBy(fn (Standing $standing): int => $standing->playerId);
            foreach ($top as $standing) {
                $then = $standings->get($standing->playerId);
                if ($then === null) {
                    continue;
                }
                $last = $histories[$standing->playerId] ?? [];
                if ($then->rank !== (end($last)['rank'] ?? null)) {
                    $histories[$standing->playerId][] = ['date' => (string) $day, 'rank' => $then->rank];
                }
                $last = $pointsHistories[$standing->playerId] ?? [];
                if ($then->points !== (end($last)['points'] ?? null)) {
                    $pointsHistories[$standing->playerId][] = ['date' => (string) $day, 'points' => $then->points];
                }
            }
        }

        return array_values($top->map(fn (Standing $standing): array => [
            'player_id' => $standing->playerId,
            'rank' => $standing->rank,
            'points' => $standing->points,
            'history' => $histories[$standing->playerId] ?? [],
            'points_history' => $pointsHistories[$standing->playerId] ?? [],
        ])->all());
    }
}
