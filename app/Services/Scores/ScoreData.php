<?php

namespace App\Services\Scores;

use App\Enums\ScoreAttribution;
use App\Models\Score;
use Illuminate\Support\Carbon;

/** One score of a `POST /scores` batch, validated by StoreScoresRequest. */
final readonly class ScoreData
{
    public function __construct(
        public string $id,
        public string $playerId,
        public string $romname,
        public string $table,
        public int $score,
        public ?int $rankOnCabinet,
        public Carbon $achievedAt,
        public ?string $startupId,
        public ScoreAttribution $attribution,
    ) {}

    /**
     * @param  array<string, mixed>  $score
     */
    public static function fromValidated(array $score): self
    {
        return new self(
            id: strtolower((string) $score['id']),
            playerId: strtolower((string) $score['player_id']),
            romname: (string) $score['romname'],
            table: isset($score['table']) ? (string) $score['table'] : Score::DEFAULT_TABLE,
            score: (int) $score['score'],
            rankOnCabinet: isset($score['rank_on_cabinet']) ? (int) $score['rank_on_cabinet'] : null,
            achievedAt: Carbon::parse((string) $score['achieved_at']),
            startupId: isset($score['startup_id']) ? strtolower((string) $score['startup_id']) : null,
            attribution: isset($score['attribution']) ? ScoreAttribution::from((string) $score['attribution']) : ScoreAttribution::Initials,
        );
    }
}
