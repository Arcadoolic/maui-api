<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cabinet's vote on a game and its number of plays there (docs/DECISIONS.md D75).
 *
 * @property int $id
 * @property int $client_id
 * @property int $game_id
 * @property int $vote 1 thumbs up, 0 neutral or not voted, -1 thumbs down.
 * @property int $play_count
 * @property Carbon|null $last_played_at
 * @property Carbon|null $voted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Client $client
 * @property-read Game $game
 */
class GameOpinion extends Model
{
    public const VOTE_DOWN = -1;

    public const VOTE_NEUTRAL = 0;

    public const VOTE_UP = 1;

    protected $fillable = ['client_id', 'game_id', 'vote', 'play_count', 'last_played_at', 'voted_at'];

    protected function casts(): array
    {
        return [
            'vote' => 'integer',
            'play_count' => 'integer',
            'last_played_at' => 'datetime',
            'voted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
