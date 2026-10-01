<?php

namespace Database\Factories;

use App\Enums\PlayerStatus;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pseudo_3' => fake()->unique()->regexify('[A-Z]{3}'),
            'is_public' => false,
            'status' => PlayerStatus::Active,
            'pin' => '1234',
        ];
    }

    public function disabled(): static
    {
        return $this->state(['status' => PlayerStatus::Disabled]);
    }
}
