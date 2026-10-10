<?php

use App\Models\Client;
use App\Models\Game;
use App\Models\GameOpinion;
use App\Services\Popularity\GamePopularity;
use App\Services\Popularity\Popularity;

// hiscores:popularity and dev:simulate-fleet (docs/DECISIONS.md D76).

it('makes up a fleet with every kind of game, the same for the same seed', function () {
    Game::factory()->count(200)->create();
    Game::factory()->uncatalogued()->create();

    $this->artisan('dev:simulate-fleet', ['--cabinets' => 12, '--seed' => 7])->assertSuccessful();

    expect(Client::query()->where('name', 'like', 'sim_%')->count())->toBe(12);
    $first = GameOpinion::query()->orderBy('client_id')->orderBy('game_id')->get(['game_id', 'vote', 'play_count'])->toArray();
    $labels = app(Popularity::class)->all()->countBy(fn (GamePopularity $game): string => $game->label->value ?? 'none');
    expect($labels->keys()->sort()->values()->all())->toBe(['addictive', 'divisive', 'hidden_gem', 'hit', 'missed_date', 'none']);

    // Run again: the former fleet is replaced, not added to.
    $this->artisan('dev:simulate-fleet', ['--cabinets' => 12, '--seed' => 7])->assertSuccessful();
    expect(Client::query()->where('name', 'like', 'sim_%')->count())->toBe(12)
        ->and(GameOpinion::query()->orderBy('client_id')->orderBy('game_id')->get(['game_id', 'vote', 'play_count'])->toArray())->toBe($first);
});

it('removes the simulated cabinets only', function () {
    Game::factory()->count(20)->create();
    [$real] = cabinetWithToken(['name' => 'broken_terry_bogard']);
    Game::query()->firstOrFail()->opinions()->create(['client_id' => $real->id, 'vote' => 1, 'play_count' => 3]);
    $this->artisan('dev:simulate-fleet', ['--cabinets' => 3])->assertSuccessful();

    $this->artisan('dev:simulate-fleet', ['--reset' => true])->assertSuccessful();

    expect(Client::query()->pluck('name')->all())->toBe(['broken_terry_bogard'])
        ->and(GameOpinion::query()->count())->toBe(1);
});

it('refuses to simulate outside development', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('dev:simulate-fleet')->assertFailed();

    expect(Client::query()->count())->toBe(0);
});

it('needs a catalog to simulate on', function () {
    $this->artisan('dev:simulate-fleet')->assertFailed();
});

it('shows the games by popularity', function () {
    $liked = Game::factory()->create(['romname' => 'liked']);
    foreach (range(1, 4) as $ignored) {
        $liked->opinions()->create(['client_id' => Client::factory()->create()->id, 'vote' => 1, 'play_count' => 30, 'last_played_at' => now()]);
    }
    Game::factory()->create(['romname' => 'tried'])->opinions()
        ->create(['client_id' => Client::factory()->create()->id, 'vote' => 0, 'play_count' => 1]);

    $this->artisan('hiscores:popularity')
        ->expectsOutputToContain('labels from 3 votes')
        ->expectsOutputToContain('1 hit')
        ->expectsOutputToContain('liked')
        ->assertSuccessful();
    $this->artisan('hiscores:popularity', ['--label' => 'hit', '--min-votes' => 5])
        ->expectsOutputToContain('0 hit')
        ->doesntExpectOutputToContain('tried')
        ->assertSuccessful();
    $this->artisan('hiscores:popularity', ['--label' => 'best'])->assertFailed();
});
