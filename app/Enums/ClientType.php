<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ClientType: string implements HasLabel
{
    /** An arcade cabinet running MAUI. */
    case Maui = 'maui';

    /** A technical account feeding the catalog (Lot 2). */
    case Service = 'service';

    /** A chat bot reading the shared leaderboards (docs/DECISIONS.md D57). */
    case Bot = 'bot';

    /**
     * Sanctum abilities granted to this type of client.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Maui => ['session', 'scores:write', 'scores:read', 'repository:read', 'players'],
            self::Service => ['catalog:write', 'repository:read'],
            self::Bot => ['leaderboards:read'],
        };
    }

    /**
     * A technical account: named by the admin, token issued in the back
     * office, no machine binding (D39, D47, D57).
     */
    public function isService(): bool
    {
        return $this !== self::Maui;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Maui => __('Cabinet'),
            self::Service => __('Service account'),
            self::Bot => __('Bot'),
        };
    }
}
