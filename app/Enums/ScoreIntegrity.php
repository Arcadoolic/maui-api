<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** What the cabinet's report on a game says of a score (docs/DECISIONS.md D87). */
enum ScoreIntegrity: string implements HasColor, HasLabel
{
    /** Nothing in the report that changes how the game was played. */
    case Clean = 'clean';

    /** Something worth a look, legitimate on its own: a DIP switch, long pauses, a saved state... */
    case Check = 'check';

    /** Something that makes the game easier: a state loaded, a cheat plugin, a modified ROM... */
    case Suspect = 'suspect';

    public function getLabel(): string
    {
        return match ($this) {
            self::Clean => __('Clean'),
            self::Check => __('To check'),
            self::Suspect => __('Suspect'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Clean => 'success',
            self::Check => 'warning',
            self::Suspect => 'danger',
        };
    }
}
