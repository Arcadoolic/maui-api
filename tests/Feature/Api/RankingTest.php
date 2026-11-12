<?php

use App\Enums\ClientType;
use App\Models\Game;
use App\Models\Player;

// The global podium shown by a cabinet, its hiscores board (docs/DECISIONS.md D100).

/** One player per initials on the game, best first. */
function cabinetBoard(Game $game, array $pseudos): void
{
    foreach (array_values($pseudos) as $index => $pseudo) {
        $player = Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
        leaderboardScore($game, $player, 10_000 - $index * 100);
    }
}

it('answers the global podium, as the front ranks it, with the avatars', function () {
    cabinetBoard(Game::factory()->create(), ['ACE', 'BOB', 'CAT']);
    cabinetBoard(Game::factory()->create(), ['BOB', 'ACE']);
    $ace = Player::query()->where('pseudo_3', 'ACE')->firstOrFail();
    $ace->forceFill(['avatar_hash' => str_repeat('a', 64)])->save();

    $response = $this->getJson('/api/v1/ranking', cabinetHeaders(...cabinetWithToken()))->assertOk();

    expect($response->json('ranking.*.player.pseudo_3'))->toBe(['ACE', 'BOB', 'CAT'])
        ->and($response->json('ranking.0'))->toBe([
            'rank' => 1, 'player' => ['id' => $ace->uuid, 'pseudo_3' => 'ACE', 'avatar' => str_repeat('a', 64)],
            'points' => 70, 'games' => 2, 'crowns' => 1, 'podiums' => 2,
        ])
        ->and($response->json('ranking.2.player.avatar'))->toBeNull();
});

it('answers the first 25 players, or as many as asked up to 50', function () {
    $pseudos = array_map(fn (int $index): string => 'A'.chr(65 + intdiv($index, 26)).chr(65 + $index % 26), range(0, 29));
    cabinetBoard(Game::factory()->create(), $pseudos);
    $headers = cabinetHeaders(...cabinetWithToken());

    $this->getJson('/api/v1/ranking', $headers)->assertOk()->assertJsonCount(25, 'ranking');
    $this->getJson('/api/v1/ranking?limit=30', $headers)->assertOk()->assertJsonCount(30, 'ranking');
    $this->getJson('/api/v1/ranking?limit=51', $headers)->assertUnprocessable();
});

it('leaves out private players', function () {
    leaderboardScore(Game::factory()->create(), Player::factory()->create(['is_public' => false]), 500);

    $this->getJson('/api/v1/ranking', cabinetHeaders(...cabinetWithToken()))->assertOk()->assertExactJson(['ranking' => []]);
});

it('needs the scores:read ability', function () {
    [$client, $token] = cabinetWithToken(['type' => ClientType::Service]);

    $this->getJson('/api/v1/ranking', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});
