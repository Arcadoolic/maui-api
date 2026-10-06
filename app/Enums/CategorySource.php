<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The MAME support file a category comes from (docs/DECISIONS.md D47). */
enum CategorySource: string implements HasLabel
{
    /** genre.ini: one level. */
    case Genre = 'genre';

    /** catver.ini: genre, then subgenre as its child. */
    case Catver = 'catver';

    public function getLabel(): string
    {
        return match ($this) {
            self::Genre => __('Genre'),
            self::Catver => __('Catver'),
        };
    }
}
