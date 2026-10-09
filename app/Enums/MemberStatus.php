<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Set by an admin: a disabled member can no longer log in to the front (docs/DECISIONS.md D64). */
enum MemberStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Disabled => __('Disabled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Disabled => 'danger',
        };
    }
}
