<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A MAME game, keyed by its romname (docs/DECISIONS.md D47).
 *
 * @property int $id
 * @property string $romname
 * @property string $description
 * @property string|null $manufacturer
 * @property string|null $year
 * @property string|null $parent_romname
 * @property int|null $player_sim
 * @property int|null $player_alt
 * @property int|null $genre_category_id
 * @property int|null $catver_category_id
 * @property bool $mature
 * @property bool $hiscores Whether a cabinet can read its hiscores (D74).
 * @property Carbon|null $catalogued_at Null while the game is only known from a score.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Category|null $genreCategory
 * @property-read Category|null $catverCategory
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected $fillable = [
        'romname', 'description', 'manufacturer', 'year', 'parent_romname',
        'player_sim', 'player_alt', 'genre_category_id', 'catver_category_id', 'mature', 'hiscores',
    ];

    protected $attributes = [
        'mature' => false,
        'hiscores' => false,
    ];

    protected function casts(): array
    {
        return [
            'player_sim' => 'integer',
            'player_alt' => 'integer',
            'mature' => 'boolean',
            'hiscores' => 'boolean',
            'catalogued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function genreCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'genre_category_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function catverCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'catver_category_id');
    }

    /**
     * What ScreenScraper knows of the game, null until it has been asked (D68).
     *
     * @return HasOne<GameDetail, $this>
     */
    public function detail(): HasOne
    {
        return $this->hasOne(GameDetail::class);
    }

    /** @return HasMany<GameMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(GameMedia::class);
    }

    /** @return HasMany<Score, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function isCatalogued(): bool
    {
        return $this->catalogued_at !== null;
    }
}
