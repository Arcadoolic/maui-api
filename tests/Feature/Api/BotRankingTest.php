<?php

use App\Models\Game;
use App\Models\Player;

// The global podium read by the Discord bot (docs/DECISIONS.md D89).

/** One player per initials on the game, best first. */
function botBoard(Game $game, array $pseudos): void
{
    foreach (array_values($pseudos) as $index => $pseudo) {
        $player = Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
        leaderboardScore($game, $player, 10_000 - $index * 100);
    }
}

it('answers the first ten of the global podium, as the front ranks them', function () {
    $pseudos = ['ACE', 'BOB', 'CAT', 'DAN', 'EVE', 'FAY', 'GUS', 'HAL', 'IDA', 'JOE', 'KIM', 'LEO'];
    botBoard(Game::factory()->create(), $pseudos);
    botBoard(Game::factory()->create(), ['BOB', 'ACE']);
    $ace = Player::query()->where('pseudo_3', 'ACE')->firstOrFail();

    $response = $this->getJson('/api/v1/bot/ranking', serviceHeaders(...botWithToken()))->assertOk();

    expect($response->json('ranking.*.player.pseudo_3'))->toBe(['ACE', 'BOB', 'CAT', 'DAN', 'EVE', 'FAY', 'GUS', 'HAL', 'IDA', 'JOE'])
        ->and($response->json('ranking.0'))->toBe([
            'rank' => 1, 'player' => ['id' => $ace->uuid, 'pseudo_3' => 'ACE'],
            'points' => 120, 'games' => 2, 'crowns' => 1, 'podiums' => 2,
        ]);
});

it('answers fewer players on demand', function () {
    botBoard(Game::factory()->create(), ['ACE', 'BOB', 'CAT']);

    $this->getJson('/api/v1/bot/ranking?limit=3', serviceHeaders(...botWithToken()))->assertOk()->assertJsonCount(3, 'ranking');
    $this->getJson('/api/v1/bot/ranking?limit=1', serviceHeaders(...botWithToken()))->assertOk()->assertJsonCount(1, 'ranking');
    $this->getJson('/api/v1/bot/ranking?limit=26', serviceHeaders(...botWithToken()))->assertUnprocessable();
});

it('leaves out private players', function () {
    leaderboardScore(Game::factory()->create(), Player::factory()->create(['is_public' => false]), 500);

    $this->getJson('/api/v1/bot/ranking', serviceHeaders(...botWithToken()))->assertOk()->assertExactJson(['ranking' => []]);
});

it('needs a bot account with the leaderboards:read ability', function (Closure $headers) {
    $this->getJson('/api/v1/bot/ranking', $headers())
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
})->with([
    'catalog service account' => [fn () => serviceHeaders(...serviceWithToken())],
    'cabinet' => [fn () => cabinetHeaders(...cabinetWithToken())],
]);
