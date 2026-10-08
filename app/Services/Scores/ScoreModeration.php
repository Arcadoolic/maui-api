<?php

namespace App\Services\Scores;

use App\Models\Score;
use App\Models\ScoreEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Back office moderation of scores, recorded in the audit log with the admin
 * as causer (docs/DECISIONS.md D50). A hidden score leaves the leaderboards
 * and the player's best: the player can beat a lower score again. Its score
 * event is retracted with it (D60).
 */
final class ScoreModeration
{
    public const LOG_NAME = 'scores';

    public function hide(Score $score): void
    {
        $score->hide();
        ScoreEvent::query()->where('score_id', $score->id)->update(['retracted_at' => now()]);
        $this->audit($score, 'score.hidden');
    }

    public function unhide(Score $score): void
    {
        $score->unhide();
        ScoreEvent::query()->where('score_id', $score->id)->update(['retracted_at' => null]);
        $this->audit($score, 'score.shown');
    }

    private function audit(Score $score, string $event): void
    {
        $admin = Auth::user();

        activity(self::LOG_NAME)
            ->performedOn($score)
            ->causedBy($admin instanceof User ? $admin : null)
            ->event($event)
            ->log($event);
    }
}
