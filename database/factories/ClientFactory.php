<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->userName(),
            'owner_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'type' => ClientType::Maui,
            'status' => ClientStatus::Active,
        ];
    }

    public function service(): static
    {
        return $this->state(fn () => [
            'type' => ClientType::Service,
            'name' => fake()->unique()->word().'_importer',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => ClientStatus::Disabled]);
    }
}
