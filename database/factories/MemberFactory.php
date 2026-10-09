<?php

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'discord_id' => (string) fake()->unique()->numberBetween(100_000_000_000_000_000, 999_999_999_999_999_999),
            'username' => fake()->unique()->userName(),
            'display_name' => null,
            'discord_avatar' => null,
            'status' => MemberStatus::Active,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['status' => MemberStatus::Disabled]);
    }
}
