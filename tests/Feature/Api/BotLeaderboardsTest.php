<?php

use App\Models\Client;
use App\Models\Game;
use App\Models\Player;

// Shared leaderboards read by the Discord bot (docs/DECISIONS.md D57).

/**
 * @return array<string, string>
 */
function botHeaders(): array
{
    [$client, $token] = botWithToken();

    return serviceHeaders($client, $token);
}

describe('available leaderboards', function () {
    it('lists each game and table with a visible score, by game description', function () {
        $pacman = Game::factory()->create(['romname' => 'pacman', 'description' => 'Pac-Man (Midway)']);
        $dkong = Game::factory()->create(['romname' => 'dkong', 'description' => 'Donkey Kong (US set 1)']);
        $player = publicPlayer('NOB');
        leaderboardScore($pacman, $player, 100);
        leaderboardScore($dkong, $player, 200);
        leaderboardScore($dkong, publicPlayer('SKI'), 300);
        leaderboardScore($dkong, $player, 400, attributes: ['table' => 'level_2']);

        $this->getJson('/api/v1/bot/leaderboards', botHeaders())
            ->assertOk()
            ->assertExactJson(['leaderboards' => [
                ['romname' => 'dkong', 'description' => 'Donkey Kong (US set 1)', 'table' => 'default'],
                ['romname' => 'dkong', 'description' => 'Donkey Kong (US set 1)', 'table' => 'level_2'],
                ['romname' => 'pacman', 'description' => 'Pac-Man (Midway)', 'table' => 'default'],
            ]]);
    });

    it('leaves out games without a visible score', function () {
        Game::factory()->create(['romname' => 'galaga']);
        $hidden = Game::factory()->create(['romname' => 'dkong']);
        leaderboardScore($hidden, publicPlayer('NOB'), 900, attributes: ['hidden_at' => now()]);
        leaderboardScore($hidden, Player::factory()->create(['is_public' => false]), 500);

        $this->getJson('/api/v1/bot/leaderboards', botHeaders())
            ->assertOk()
            ->assertExactJson(['leaderboards' => []]);
    });
});

describe('one leaderboard', function () {
    it('answers the leaderboard of a game, as the cabinets get it', function () {
        $game = Game::factory()->create(['romname' => 'dkong']);
        $nob = publicPlayer('NOB');
        leaderboardScore($game, $nob, 19_200, Client::factory()->create(['name' => 'blue_cabinet']), ['achieved_at' => '2026-10-01T10:00:00Z']);

        $this->getJson('/api/v1/bot/leaderboards/dkong', botHeaders())
            ->assertOk()
            ->assertExactJson([
                'romname' => 'dkong',
                'table' => 'default',
                'entries' => [
                    ['rank' => 1, 'player' => ['id' => $nob->uuid, 'pseudo_3' => 'NOB', 'avatar' => null], 'score' => 19_200,
                        'achieved_at' => '2026-10-01T10:00:00+00:00', 'cabinet' => 'blue_cabinet'],
                ],
            ]);
    });

    it('reads another table', function () {
        $game = Game::factory()->create(['romname' => 'dkong']);
        leaderboardScore($game, publicPlayer('NOB'), 100);
        leaderboardScore($game, publicPlayer('SKI'), 200, attributes: ['table' => 'level_2']);

        $this->getJson('/api/v1/bot/leaderboards/dkong?table=level_2', botHeaders())
            ->assertOk()
            ->assertJsonPath('table', 'level_2')
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.player.pseudo_3', 'SKI');
    });

    it('answers an empty leaderboard for an unknown game', function () {
        $this->getJson('/api/v1/bot/leaderboards/galaga', botHeaders())
            ->assertOk()
            ->assertExactJson(['romname' => 'galaga', 'table' => 'default', 'entries' => []]);
    });
});

it('needs a bot account with the leaderboards:read ability', function (string $path, Closure $headers) {
    $this->getJson($path, $headers())
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
})->with([
    'list, catalog service account' => ['/api/v1/bot/leaderboards', fn () => serviceHeaders(...serviceWithToken())],
    'list, cabinet' => ['/api/v1/bot/leaderboards', fn () => cabinetHeaders(...cabinetWithToken())],
    'one, catalog service account' => ['/api/v1/bot/leaderboards/dkong', fn () => serviceHeaders(...serviceWithToken())],
    'one, cabinet' => ['/api/v1/bot/leaderboards/dkong', fn () => cabinetHeaders(...cabinetWithToken())],
]);

it('gives a bot account nothing else', function (string $method, string $path) {
    $this->json($method, $path, [], botHeaders())
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
})->with([
    'catalog push' => ['PUT', '/api/v1/catalog/games'],
    'repository' => ['GET', '/api/v1/repository'],
]);

it('refuses an anonymous request', function () {
    $this->getJson('/api/v1/bot/leaderboards')
        ->assertUnauthorized();
});
