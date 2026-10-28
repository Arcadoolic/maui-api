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

        // Ages from the start of the day: the weights, and so the draw, hold all day.
        $today = $day->copy()->startOfDay();
        $drawn = $leaders->sortByDesc(function (object $leader) use ($popularity, $today, $halfLife): float {
            $age = $today->diffInDays($leader->achieved_at, true);
            $weight = max(1, $popularity->get($leader->game_id)->cabinets ?? 0) * 0.5 ** ($age / $halfLife);

            // A weighted draw without replacement: the greatest u^(1/weight) win, u drawn from the
            // day and the game so that the day gives the same draw.
            return log(self::uniform($today->toDateString().'|'.$leader->game_id)) / $weight;
        });

        // At most `per_player` games of a player first; the row then filled with the rest of the draw.
        $perPlayers = [];
        $varied = $drawn->filter(function (object $leader) use (&$perPlayers, $perPlayer): bool {
            $perPlayers[$leader->player_id] = ($perPlayers[$leader->player_id] ?? 0) + 1;

            return $perPlayers[$leader->player_id] <= $perPlayer;
        });

        return $varied->concat($drawn->diffKeys($varied))->take($limit)->map(fn (object $leader): Challenge => new Challenge(
            gameId: (int) $leader->game_id,
            playerId: (int) $leader->player_id,
            score: (int) $leader->score,
            since: (string) $leader->achieved_at,
        ))->values();
    }

    /** A number in ]0, 1[ drawn from `$seed`, the same for the same seed. */
    private static function uniform(string $seed): float
    {
        return (hexdec(substr(hash('sha256', $seed), 0, 13)) + 1) / (16 ** 13 + 1);
    }
}
