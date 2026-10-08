<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a stored score changed on its leaderboard: a movement, flavors, the
 * facts behind them and the sentence that tells it (docs/DECISIONS.md D60).
 *
 * @property int $id
 * @property string $uuid
 * @property int $score_id
 * @property int $player_id
 * @property int $game_id
 * @property string $table
 * @property string $movement
 * @property list<string> $flavors
 * @property int $score
 * @property int|null $rank_before
 * @property int $rank_after
 * @property int|null $displaced_player_id
 * @property array<string, mixed> $facts
 * @property string $message
 * @property int $importance
 * @property bool $announceable
 * @property Carbon $occurred_at
 * @property Carbon $recorded_at
 * @property Carbon|null $retracted_at
 * @property-read Player $player
 * @property-read Game $game
 */
class ScoreEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    /**
     * HasUuids fills the public `uuid` column, not the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'flavors' => 'array',
            'facts' => 'array',
            'score' => 'integer',
            'rank_before' => 'integer',
            'rank_after' => 'integer',
            'importance' => 'integer',
            'announceable' => 'boolean',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'retracted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
