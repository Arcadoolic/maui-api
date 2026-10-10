<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        $romname = fake()->unique()->regexify('[a-z]{4,8}');

        return [
            'romname' => $romname,
            'description' => ucfirst($romname),
            'manufacturer' => fake()->company(),
            'year' => (string) fake()->numberBetween(1978, 2005),
            'catalogued_at' => now(),
            'hiscores' => true,
        ];
    }

    /** Catalogued, but no cabinet can read its hiscores (D74). */
    public function withoutHiscores(): static
    {
        return $this->state(['hiscores' => false]);
    }

    /** Known from a score only: romname as description, nothing else (D47). */
    public function uncatalogued(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => $attributes['romname'],
            'manufacturer' => null,
            'year' => null,
            'catalogued_at' => null,
            'hiscores' => false,
        ]);
    }
}
