<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Score>
 */
class ScoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'game_id' => Game::factory(),
            'client_id' => Client::factory(),
            'score' => fake()->numberBetween(1_000, 999_999),
            'achieved_at' => now(),
            'received_at' => now(),
        ];
    }

    public function hidden(): static
    {
        return $this->state(['hidden_at' => now()]);
    }
}
