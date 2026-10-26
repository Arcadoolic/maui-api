<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Player;
use App\Models\ScoreRecovery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScoreRecovery>
 */
class ScoreRecoveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'player_id' => Player::factory(),
            'romname' => 'atetris',
            'score' => 250_000,
            'achieved_at' => now()->subDay(),
            'reason' => 'Score on the player\'s screenshot, .hi checked.',
            'expires_at' => now()->addDays(ScoreRecovery::KEEP_OPEN_DAYS),
        ];
    }
}
