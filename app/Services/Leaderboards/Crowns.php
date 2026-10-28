<?php

namespace App\Services\Leaderboards;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Champions (docs/DECISIONS.md D96): a first place and its game put forward next to the global
 * podium, so that a player with little time who dominates a game shows too. Only the first
 * places of the default tables where another player is ranked: a lone first place beats nobody,
 * it waits for challengers (D95).
 *
 * Drawn for the day (DailyDraw): a first place weighs the players it beats times the days it
 * holds, and a player on the global podium `podium_weight` of that, since the podium already
 * shows them. One first place of each player first.
 */
final class Crowns
{
    public function __construct(private readonly Rankings $rankings, private readonly GlobalRanking $ranking) {}

    /** @return Collection<int, HeldCrown> In the order drawn. */
    public function champions(CarbonInterface $day, int $limit): Collection
    {
        $podium = $this->ranking->standings()->take(3)->map(fn (Standing $standing): int => $standing->playerId)->all();
        $podiumWeight = max(0.01, (float) config('hiscores.crowns.podium_weight'));

        return DailyDraw::draw(
            $this->held($day),
            $day,
            fn (HeldCrown $crown): float => ($crown->players - 1) * max(1, $crown->days)
                * (in_array($crown->playerId, $podium, true) ? $podiumWeight : 1.0),
            fn (HeldCrown $crown): string => (string) $crown->gameId,
            fn (HeldCrown $crown): int => $crown->playerId,
            1,
        )->take($limit)->values();
    }

    /**
     * Every first place where others are ranked. A first place holds from the holder's first
     * score above the second best one: improving it does not start the count again. No history
     * of the leaderboards is kept, so a second best made later is not known to have come later.
     *
     * @return Collection<int, HeldCrown>
     */
    private function held(CarbonInterface $day): Collection
    {
        $since = Leaderboards::visibleScores()->toBase()
            ->joinSub($this->contested(), 'crown', fn ($join) => $join
                ->on('scores.game_id', '=', 'crown.game_id')
                ->on('scores.player_id', '=', 'crown.player_id')
                ->whereColumn('scores.table', 'crown.table'))
            ->whereColumn('scores.score', '>', 'crown.below_score')
            ->groupBy('scores.game_id')
            ->select('scores.game_id', DB::raw('min(scores.achieved_at) as since'))
            ->pluck('since', 'game_id');

        return $this->contested()->get()->map(function (object $crown) use ($since, $day): HeldCrown {
            $from = Carbon::parse((string) ($since[$crown->game_id] ?? $crown->achieved_at), 'UTC');

            return new HeldCrown(
                gameId: (int) $crown->game_id,
                playerId: (int) $crown->player_id,
                score: (int) $crown->score,
                players: (int) $crown->players,
                since: $from->toIso8601String(),
                days: (int) floor($from->diffInDays($day, true)),
            );
        });
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
