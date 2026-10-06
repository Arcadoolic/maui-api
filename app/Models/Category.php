<?php

namespace App\Models;

use App\Enums\CategorySource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A game category, from genre.ini or catver.ini (docs/DECISIONS.md D47).
 *
 * @property int $id
 * @property CategorySource $source
 * @property string $name
 * @property int|null $parent_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Category|null $parent
 */
class Category extends Model
{
    protected $fillable = ['source', 'name', 'parent_id'];

    protected function casts(): array
    {
        return [
            'source' => CategorySource::class,
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** "Platform / Run Jump" for a catver subgenre, the name alone otherwise. */
    public function fullName(): string
    {
        return $this->parent !== null ? $this->parent->name.' / '.$this->name : $this->name;
    }
}
