<?php

namespace App\Services\Scores;

use App\Enums\ScoreRecoveryStatus;
use App\Models\Diagnostic;
use App\Models\Player;
use App\Models\Score;
use App\Models\ScoreRecovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Requests to recover a score the cabinet's file holds but MAUI-API never got
 * (docs/DECISIONS.md D93): opened by an administrator, closed by the cabinet's
 * answer (ScoreIntake, POST /score-recoveries/{id}/result) or when they expire.
 * Both recorded in the audit log.
 */
final class ScoreRecoveries
{
    public const LOG_NAME = 'scores';

    public function open(
        Player $player,
        int $clientId,
        string $romname,
        string $table,
        int $score,
        Carbon $achievedAt,
        string $reason,
        ?Diagnostic $diagnostic = null,
    ): ScoreRecovery {
        $recovery = new ScoreRecovery;
        $recovery->forceFill([
            'client_id' => $clientId,
            'player_id' => $player->id,
            'romname' => $romname,
            'table' => $table,
            'score' => $score,
            'achieved_at' => $achievedAt,
            'reason' => $reason,
            'diagnostic_id' => $diagnostic?->id,
            'expires_at' => now()->addDays(ScoreRecovery::KEEP_OPEN_DAYS),
        ])->save();

        activity(self::LOG_NAME)
            ->performedOn($recovery)
            ->event('score_recovery.created')
            ->withProperties(['romname' => $romname, 'table' => $table, 'score' => $score, 'player' => $player->pseudo_3])
            ->log('score_recovery.created');

        return $recovery;
    }

    /**
     * Closes a request with the cabinet's answer. Nothing for a score that answers no request.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function close(?ScoreRecovery $recovery, ScoreRecoveryStatus $status, ?array $result = null, ?Score $score = null): void
    {
        if ($recovery === null) {
            return;
        }
        $recovery->forceFill([
            'status' => $status,
            'result' => $result,
            'score_id' => $score?->id,
            'closed_at' => now(),
        ])->save();

        $log = activity(self::LOG_NAME)->performedOn($recovery);
        // The cabinet answered; an expiry has no one behind it.
        if ($status !== ScoreRecoveryStatus::Expired) {
            $log->causedBy($recovery->client);
        }
        $log->event('score_recovery.closed')
            ->withProperties(['status' => $status->value])
            ->log('score_recovery.closed');
    }

    /** The requests left open past their date. Returns how many. */
    public function expire(): int
    {
        $expired = 0;
        ScoreRecovery::query()
            ->where('status', ScoreRecoveryStatus::Pending)
            ->where('expires_at', '<=', now())
            ->lazyById()
            ->each(function (ScoreRecovery $recovery) use (&$expired): void {
                DB::transaction(fn () => $this->close($recovery, ScoreRecoveryStatus::Expired));
                $expired++;
            });

        return $expired;
    }
}
