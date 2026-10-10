<?php

namespace App\Services\Opinions;

use Illuminate\Support\Carbon;

/** One validated entry of a cabinet's report (docs/DECISIONS.md D75). */
final readonly class OpinionData
{
    public function __construct(
        public string $romname,
        public int $vote,
        public int $playCount,
        public ?Carbon $lastPlayedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $opinion  Validated by PutOpinionsRequest.
     */
    public static function fromValidated(array $opinion): self
    {
        $lastPlayedAt = $opinion['last_played_at'] ?? null;

        return new self(
            romname: is_string($opinion['romname'] ?? null) ? $opinion['romname'] : '',
            vote: is_int($opinion['vote'] ?? null) ? $opinion['vote'] : 0,
            playCount: is_int($opinion['play_count'] ?? null) ? $opinion['play_count'] : 0,
            lastPlayedAt: is_string($lastPlayedAt) ? Carbon::parse($lastPlayedAt) : null,
        );
    }
}
