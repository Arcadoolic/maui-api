<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\Leaderboards\GlobalRanking;
use App\Services\Leaderboards\Standing;
use Illuminate\Console\Command;

/**
 * Prints the global podium next to the former rule, to tune the scale on
 * real data before changing config/hiscores.php (docs/DECISIONS.md D70).
 * Changes nothing.
 */
final class ShowRankingCommand extends Command
{
    protected $signature = 'hiscores:ranking
        {--best= : Results counted per player, instead of the configured number}
        {--full= : Ranked players from which a leaderboard gives its full points}';

    protected $description = 'Show the global podium, next to the former 500/300/50 rule';

    public function handle(GlobalRanking $ranking): int
    {
        $rules = GlobalRanking::rules();
        if ($this->option('best') !== null) {
            $rules['best_results'] = max(1, (int) $this->option('best'));
        }
        if ($this->option('full') !== null) {
            $rules['full_competition_players'] = max(2, (int) $this->option('full'));
        }

        $standings = $ranking->standings($rules);
        $names = Player::query()->whereIn('id', $standings->map(fn (Standing $standing): int => $standing->playerId))->pluck('pseudo_3', 'id');
        $formerRanks = array_flip($standings->sortByDesc(fn (Standing $standing): int => $standing->formerPoints)->values()
            ->map(fn (Standing $standing): int => $standing->playerId)->all());

        $this->line(sprintf('Best %d results, full points from %d ranked players.', $rules['best_results'], $rules['full_competition_players']));
        $this->table(
            ['Rank', 'Player', 'Points', 'Counted', 'Games', 'Crowns', 'Podiums', 'Former points', 'Former rank'],
            $standings->map(fn (Standing $standing): array => [
                $standing->rank,
                $names[$standing->playerId] ?? '?',
                $standing->points,
                $standing->counted(),
                $standing->games,
                $standing->crowns,
                $standing->podiums,
                $standing->formerPoints,
                $formerRanks[$standing->playerId] + 1,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
