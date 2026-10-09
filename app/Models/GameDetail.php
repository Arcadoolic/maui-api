<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What ScreenScraper knows of a game (docs/DECISIONS.md D68).
 *
 * @property int $id
 * @property int $game_id
 * @property bool $found
 * @property int|null $screenscraper_id
 * @property string|null $synopsis_fr
 * @property string|null $synopsis_en
 * @property string|null $developer
 * @property string|null $publisher
 * @property int|null $rating Out of 20.
 * @property string|null $players
 * @property int|null $rotation
 * @property string|null $resolution
 * @property bool|null $joystick
 * @property int|null $buttons
 * @property list<string>|null $genres
 * @property Carbon $scraped_at
 */
class GameDetail extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'found' => 'boolean',
            'screenscraper_id' => 'integer',
            'rating' => 'integer',
            'rotation' => 'integer',
            'joystick' => 'boolean',
            'buttons' => 'integer',
            'genres' => 'array',
            'scraped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
