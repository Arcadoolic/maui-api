<?php

namespace App\Enums;

enum ClientType: string
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
}
