<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a request to recover a score stands (docs/DECISIONS.md D93). */
enum ScoreRecoveryStatus: string implements HasColor, HasLabel
{
    /** Waiting for the cabinet to read it. */
    case Pending = 'pending';

    /** The cabinet found the score and MAUI-API stored it. */
    case Accepted = 'accepted';

    /** Found, but not above the player's best: nothing to store. */
    case NotImproved = 'not_improved';

    /** The cabinet did not find this score in its file. */
    case NotFound = 'not_found';

    /** Found, refused by the intake (a disabled player...). */
    case Rejected = 'rejected';

    /** No answer from the cabinet in time. */
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Accepted => __('Recovered'),
            self::NotImproved => __('Not improved'),
            self::NotFound => __('Not found'),
            self::Rejected => __('Rejected'),
            self::Expired => __('Expired'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Accepted => 'success',
            self::NotImproved, self::Expired => 'gray',
            self::NotFound, self::Rejected => 'warning',
        };
    }
}
