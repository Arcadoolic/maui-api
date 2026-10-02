<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Score;

// dev:reset-scores: start the scores over during development.

it('deletes the scores and the games known from scores only', function () {
    $catalogued = Game::factory()->create();
    $bare = Game::factory()->uncatalogued()->create();
    Score::factory()->create(['game_id' => $catalogued->id]);
    Score::factory()->create(['game_id' => $bare->id]);
    $players = Player::query()->count();

    $this->artisan('dev:reset-scores')->assertSuccessful();

    expect(Score::query()->count())->toBe(0)
        ->and(Game::query()->pluck('id')->all())->toBe([$catalogued->id])
        ->and(Player::query()->count())->toBe($players);
});

it('keeps the bare games on demand', function () {
    $bare = Game::factory()->uncatalogued()->create();
    Score::factory()->create(['game_id' => $bare->id]);

    $this->artisan('dev:reset-scores', ['--keep-games' => true])->assertSuccessful();

    expect(Game::query()->whereKey($bare->id)->exists())->toBeTrue();
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Score::factory()->create();

    $this->artisan('dev:reset-scores')->assertFailed();

    expect(Score::query()->count())->toBe(1);
});
