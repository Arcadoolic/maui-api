<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A player's vote on a game, given on the hiscores front by its member
 * (docs/DECISIONS.md D86). Counted as a cabinet's vote (D75) in the popularity.
 *
 * @property int $id
 * @property int $player_id
 * @property int $game_id
 * @property int $vote GameOpinion::VOTE_UP or GameOpinion::VOTE_DOWN: no row while neutral.
 * @property Carbon $voted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Player $player
 * @property-read Game $game
 */
class PlayerOpinion extends Model
{
    protected $fillable = ['player_id', 'game_id', 'vote', 'voted_at'];

    protected function casts(): array
    {
        return [
            'vote' => 'integer',
            'voted_at' => 'datetime',
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
