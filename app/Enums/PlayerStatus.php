<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Set by an admin. A PIN lock is separate (Player::isLocked()): the API
 * reports it as the `locked` status of an active player (docs/DECISIONS.md D48).
 */
enum PlayerStatus: string implements HasColor, HasLabel
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
