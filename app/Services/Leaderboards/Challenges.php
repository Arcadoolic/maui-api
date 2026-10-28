<?php

namespace App\Services\Leaderboards;

use App\Services\Popularity\GamePopularity;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * "Waiting for challengers" (docs/DECISIONS.md D95): the games a single player is ranked on,
 * drawn for the day. Each game weighs the cabinets that can play it, times the freshness of
 * the score to beat (halved every `half_life_days`), and the day seeds the draw: the games
 * change from one day to the next, not from one page to the next. At most `per_player` games
 * of the same lone player come first, so that one player does not fill the row while others
 * wait too; the rest of the draw only fills it.
 */
final class Challenges
{
    public function __construct(private readonly Rankings $rankings) {}

    /**
     * @param  Collection<int, GamePopularity>  $popularity  Keyed by game id: the cabinets of each game.
     * @param  array<int>  $excludedPlayerIds  The member's players: nobody challenges themselves.
     * @return Collection<int, Challenge> The draw, in order.
     */
    public function draw(Collection $popularity, array $excludedPlayerIds, CarbonInterface $day, int $limit): Collection
    {
        $halfLife = max(1, (int) config('hiscores.challengers.half_life_days'));
        $perPlayer = max(1, (int) config('hiscores.challengers.per_player'));
        $alone = $this->rankings->gameTotals()->havingRaw('count(distinct ranked.player_id) = 1')->select('ranked.game_id');

        $leaders = $this->rankings->rows()
            ->whereIn('ranked.game_id', $alone)
            ->where('ranked.table', 'default')
            ->whereNotIn('ranked.player_id', $excludedPlayerIds)
            ->get(['ranked.game_id', 'ranked.player_id', 'ranked.score', 'ranked.achieved_at']);

        $drawn = DailyDraw::draw(
            $leaders,
            $day,
            fn (object $leader, CarbonInterface $today): float => max(1, $popularity->get($leader->game_id)->cabinets ?? 0)
                * 0.5 ** ($today->diffInDays($leader->achieved_at, true) / $halfLife),
            fn (object $leader): string => (string) $leader->game_id,
            fn (object $leader): int => (int) $leader->player_id,
            $perPlayer,
        );

        return $drawn->take($limit)->map(fn (object $leader): Challenge => new Challenge(
            gameId: (int) $leader->game_id,
            playerId: (int) $leader->player_id,
            score: (int) $leader->score,
            since: (string) $leader->achieved_at,
        ))->values();
    }
}
