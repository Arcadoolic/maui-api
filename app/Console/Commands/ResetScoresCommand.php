<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Models\Score;
use Illuminate\Console\Command;

/**
 * Development only: empties `scores`, so that a cabinet can send its
 * hiscores again (MAUI's BO, MAUI > Online, "Send this cabinet's hiscores").
 * Players, cabinets and the catalog stay. Refused outside the local and
 * testing environments.
 */
final class ResetScoresCommand extends Command
{
    protected $signature = 'dev:reset-scores
        {--keep-games : Keep the bare games created by scores outside the catalog}';

    protected $description = 'Delete every score (development only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('dev:reset-scores only runs in the local and testing environments.');

            return self::FAILURE;
        }

        $scores = Score::query()->delete();
        $games = $this->option('keep-games')
            ? 0
            : Game::query()->whereNull('catalogued_at')->whereDoesntHave('scores')->delete();

        $this->info("{$scores} score(s) deleted, {$games} game(s) known from scores only deleted.");

        return self::SUCCESS;
    }
}
