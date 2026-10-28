<?php

namespace App\Services\Leaderboards;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The first places of the hiscores front (docs/DECISIONS.md D96), next to the global podium:
 * a player with little time can be the champion of a few games. Only the first places of the
 * default tables, and only where another player is ranked: a lone first place beats nobody,
 * it is waiting for challengers (D95).
 */
final class Crowns
{
    public function __construct(private readonly Rankings $rankings) {}

    /**
     * The players by the players their first places beat, then by the number of first places,
     * then the first to get there first.
     *
     * @return Collection<int, Champion>
     */
    public function champions(int $limit): Collection
    {
        return $this->contested()->get()
            ->groupBy('player_id')
            ->map(fn (Collection $crowns, int|string $playerId): array => [
                'player_id' => (int) $playerId,
                'beaten' => (int) $crowns->sum(fn (object $crown): int => (int) $crown->players - 1),
                'crowns' => $crowns->count(),
                'first' => (string) $crowns->min('achieved_at'),
            ])
            ->sort(fn (array $a, array $b): int => [$b['beaten'], $b['crowns'], $a['first'], $a['player_id']] <=> [$a['beaten'], $a['crowns'], $b['first'], $b['player_id']])
            ->take($limit)
            ->values()
            ->map(fn (array $champion, int $index): Champion => new Champion(
                rank: $index + 1,
                playerId: $champion['player_id'],
                beaten: $champion['beaten'],
                crowns: $champion['crowns'],
            ));
    }

    /**
     * The first places by the players they beat times the days they hold. A first place holds
     * from the holder's first score above the second best one: improving it does not start the
     * count again. No history of the leaderboards is kept, so a second best made later is not
     * known to have come later.
     *
     * @return Collection<int, HeldCrown>
     */
    public function unbeaten(int $limit): Collection
    {
        $crowns = $this->contested();
        $since = Leaderboards::visibleScores()->toBase()
            ->joinSub($crowns, 'crown', fn ($join) => $join
                ->on('scores.game_id', '=', 'crown.game_id')
                ->on('scores.player_id', '=', 'crown.player_id')
                ->whereColumn('scores.table', 'crown.table'))
            ->whereColumn('scores.score', '>', 'crown.below_score')
            ->groupBy('scores.game_id')
            ->select('scores.game_id', DB::raw('min(scores.achieved_at) as since'))
            ->pluck('since', 'game_id');
        $now = Carbon::now();

        return $this->contested()->get()
            ->map(function (object $crown) use ($since, $now): array {
                $from = Carbon::parse((string) ($since[$crown->game_id] ?? $crown->achieved_at), 'UTC');
                $days = (int) floor($from->diffInDays($now, true));

                return [
                    'game_id' => (int) $crown->game_id,
                    'player_id' => (int) $crown->player_id,
                    'score' => (int) $crown->score,
                    'players' => (int) $crown->players,
                    'since' => $from->toIso8601String(),
                    'days' => $days,
                    // A first place of the day still holds: one day at least.
                    'weight' => ((int) $crown->players - 1) * max(1, $days),
                ];
            })
            ->sort(fn (array $a, array $b): int => [$b['weight'], $a['since'], $a['game_id']] <=> [$a['weight'], $b['since'], $b['game_id']])
            ->take($limit)
            ->values()
            ->map(fn (array $crown, int $index): HeldCrown => new HeldCrown(
                rank: $index + 1,
                gameId: $crown['game_id'],
                playerId: $crown['player_id'],
                score: $crown['score'],
                players: $crown['players'],
                since: $crown['since'],
                days: $crown['days'],
            ));
    }

    /** The first places of the default tables where another player is ranked. */
    private function contested(): Builder
    {
        return $this->rankings->rows()
            ->where('ranked.table', 'default')
            ->where('ranked.rank', 1)
            ->where('ranked.players', '>=', 2);
    }
}
