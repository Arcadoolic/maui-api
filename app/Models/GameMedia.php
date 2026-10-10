<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture of a game, downloaded from ScreenScraper (docs/DECISIONS.md D68).
 *
 * @property int $id
 * @property int $game_id
 * @property string $type One of TYPES.
 * @property int $position 0 for the picture of its type; 1 and up for the other flyers (D73).
 * @property string $path On the `local` disk.
 * @property string $mime
 * @property string $hash SHA-256 of the file.
 */
class GameMedia extends Model
{
    public const DISK = 'local';

    /** Ours => ScreenScraper's media type. */
    public const TYPES = [
        'screenshot' => 'ss',
        'title' => 'sstitle',
        'logo' => 'wheel',
        'marquee' => 'marquee',
        'flyer' => 'flyer',
    ];

    /** Flyers kept per game at most: the first one and its other sides or regions. */
    public const MAX_FLYERS = 8;

    protected $table = 'game_media';

    protected $guarded = [];

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
