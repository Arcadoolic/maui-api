<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientStartup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientStartup>
 */
class ClientStartupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'client_id' => Client::factory(),
            'mame_version' => $this->faker->randomElement(['2.5.0', '2.6.0', '2.7.0']),
            'maui_version' => $this->faker->randomElement(['0.1.0', '0.2.0', '1.0.0']),
            'os' => $this->faker->randomElement(['Linux', 'Windows', 'Darwin']),
            'os_version' => $this->faker->randomElement(['5.15.0', '6.1.0', '14.0']),
            'client_datetime' => now(),
            'received_at' => null,
        ];
    }

    /**
     * Indicate that the startup was received (synced).
     */
    public function received(): static
    {
        return $this->state(fn (array $attributes) => [
            'received_at' => now(),
        ]);
    }
}
