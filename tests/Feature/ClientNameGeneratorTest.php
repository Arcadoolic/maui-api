<?php

use App\Models\Client;
use App\Services\ClientNameGenerator;

it('combines an adjective and an arcade hero in snake case', function () {
    config(['maui.names.adjectives' => ['marvelous'], 'maui.names.heroes' => ['mario']]);

    expect(app(ClientNameGenerator::class)->generate())->toBe('marvelous_mario');
});

it('appends a numeric suffix when every combination is taken', function () {
    config(['maui.names.adjectives' => ['marvelous'], 'maui.names.heroes' => ['mario']]);
    Client::factory()->create(['name' => 'marvelous_mario']);
    Client::factory()->create(['name' => 'marvelous_mario_2']);

    expect(app(ClientNameGenerator::class)->generate())->toBe('marvelous_mario_3');
});

it('draws another combination before falling back to a suffix', function () {
    config(['maui.names.adjectives' => ['marvelous', 'mighty'], 'maui.names.heroes' => ['mario']]);
    Client::factory()->create(['name' => 'marvelous_mario']);

    expect(app(ClientNameGenerator::class)->generate())->toBe('mighty_mario');
});

it('names a client automatically when none is given', function () {
    $client = Client::factory()->create(['name' => null]);

    expect($client->name)->toMatch('/^[a-z]+(_[a-z0-9]+)+$/');
});

it('ships name lists in the configuration', function () {
    expect(config('maui.names.adjectives'))->toBeArray()->not->toBeEmpty()
        ->and(config('maui.names.heroes'))->toBeArray()->not->toBeEmpty();
});
