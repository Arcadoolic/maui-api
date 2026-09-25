<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'token' => bin2hex(random_bytes(16)),
            'purpose' => $this->faker->randomElement(['initial', 'renewal']),
            'expires_at' => now()->addDays(7),
            'claimed_at' => null,
            'claimed_ip' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the invitation is claimed.
     */
    public function claimed(?User $by = null, ?string $ip = null): static
    {
        return $this->state(fn (array $attributes) => [
            'claimed_at' => now(),
            'claimed_ip' => $ip ?? '192.168.1.1',
            'created_by' => $by->id ?? User::factory(),
        ]);
    }
}
