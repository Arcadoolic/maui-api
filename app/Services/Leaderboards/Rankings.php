<?php

namespace App\Services\Leaderboards;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every shared leaderboard at once, for the hiscores front (docs/DECISIONS.md
 * D67): one row per player, game and table, with its rank and the number of
 * ranked players. Same rule as Leaderboards: the best visible score of each
 * player, best first, the one made first at equal scores.
 */
final class Rankings
{
    /**
     * Columns of `scores`, plus `rank` and `players`. To be narrowed with
     * `where()`: by game, by player...
     */
    public function rows(): Builder
    {
        $bests = Leaderboards::visibleScores()
            ->select('scores.*')
            ->distinct(['scores.game_id', 'scores.table', 'scores.player_id'])
            ->orderBy('scores.game_id')
            ->orderBy('scores.table')
            ->orderBy('scores.player_id')
            ->orderByDesc('scores.score')
            ->orderBy('scores.achieved_at')
            ->orderBy('scores.id');

        $ranked = DB::query()
            ->fromSub($bests->toBase(), 'bests')
            ->selectRaw('bests.*')
            ->selectRaw('row_number() over (partition by bests.game_id, bests."table" order by bests.score desc, bests.achieved_at, bests.id) as "rank"')
            ->selectRaw('count(*) over (partition by bests.game_id, bests."table") as players');

        return DB::query()->fromSub($ranked, 'ranked');
    }

    /**
     * What each visible player holds, all leaderboards together.
     * Columns: player_id, games, crowns, podiums, beaten, last_score_at.
     */
    public function playerTotals(): Builder
    {
        return $this->rows()
            ->groupBy('ranked.player_id')
            ->select('ranked.player_id')
            ->selectRaw('count(distinct ranked.game_id) as games')
            ->selectRaw('count(*) filter (where ranked."rank" = 1) as crowns')
            ->selectRaw('count(*) filter (where ranked."rank" <= 3) as podiums')
            ->selectRaw('coalesce(sum(ranked.players - ranked."rank"), 0) as beaten')
            ->selectRaw('max(ranked.achieved_at) as last_score_at');
    }

    /**
     * What each game with a visible score holds, all its tables together.
     * Columns: game_id, ranked_players, last_score_at.
     */
    public function gameTotals(): Builder
    {
        return $this->rows()
            ->groupBy('ranked.game_id')
            ->select('ranked.game_id')
            ->selectRaw('count(distinct ranked.player_id) as ranked_players')
            ->selectRaw('max(ranked.achieved_at) as last_score_at');
    }
}
