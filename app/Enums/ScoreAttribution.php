<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** How the cabinet knew whose score it was (docs/DECISIONS.md D61). */
enum ScoreAttribution: string implements HasLabel
{
    /** The initials the game wrote next to the score. */
    case Initials = 'initials';

    /** The game writes no name: the player was picked on the cabinet when the game was quit. */
    case Declared = 'declared';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initials => __('Initials'),
            self::Declared => __('Declared'),
        };
    }
}
