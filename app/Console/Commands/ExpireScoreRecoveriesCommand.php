<?php

namespace App\Console\Commands;

use App\Models\ScoreRecovery;
use App\Services\Scores\ScoreRecoveries;
use Illuminate\Console\Command;

/**
 * Closes the requests to recover a score that no cabinet answered within
 * their 30 days (docs/DECISIONS.md D93). Run every night by the scheduler.
 */
final class ExpireScoreRecoveriesCommand extends Command
{
    protected $signature = 'score-recoveries:expire';

    protected $description = 'Expire the score recovery requests left open past '.ScoreRecovery::KEEP_OPEN_DAYS.' days';

    public function handle(ScoreRecoveries $recoveries): int
    {
        $this->info($recoveries->expire().' score recovery request(s) expired.');

        return self::SUCCESS;
    }
}
