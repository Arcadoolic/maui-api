<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ClientType: string implements HasLabel
{
    /** An arcade cabinet running MAUI. */
    case Maui = 'maui';

    /** A technical account feeding the catalog (Lot 2). */
    case Service = 'service';

    /**
     * Sanctum abilities granted to this type of client.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Maui => ['session', 'scores:write', 'scores:read'],
            self::Service => ['catalog:write'],
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Maui => __('Cabinet'),
            self::Service => __('Service account'),
        };
    }
}
