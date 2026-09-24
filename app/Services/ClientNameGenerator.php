<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Arr;

/**
 * Generates a unique, human-readable cabinet name such as "marvelous_mario".
 */
final class ClientNameGenerator
{
    private const RANDOM_ATTEMPTS = 10;

    public function generate(): string
    {
        /** @var list<string> $adjectives */
        $adjectives = config('maui.names.adjectives');
        /** @var list<string> $heroes */
        $heroes = config('maui.names.heroes');

        // Random draws first, then every combination: keeps names varied while
        // still finding a free one when the lists are nearly exhausted.
        for ($attempt = 0; $attempt < self::RANDOM_ATTEMPTS; $attempt++) {
            $name = Arr::random($adjectives).'_'.Arr::random($heroes);
            if (! $this->isTaken($name)) {
                return $name;
            }
        }

        foreach ($adjectives as $adjective) {
            foreach ($heroes as $hero) {
                if (! $this->isTaken($adjective.'_'.$hero)) {
                    return $adjective.'_'.$hero;
                }
            }
        }

        return $this->withNumericSuffix(Arr::random($adjectives).'_'.Arr::random($heroes));
    }

    private function withNumericSuffix(string $base): string
    {
        $suffix = 2;
        while ($this->isTaken($base.'_'.$suffix)) {
            $suffix++;
        }

        return $base.'_'.$suffix;
    }

    private function isTaken(string $name): bool
    {
        return Client::query()->where('name', $name)->exists();
    }
}
