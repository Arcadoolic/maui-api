<?php

use App\Enums\ClientType;
use App\Models\Game;
use App\Models\GameMedia;
use App\Models\Player;

// The champions shown by an idle cabinet (docs/DECISIONS.md D101): the first places of the day,
// as the hiscores front puts them forward (D96).

/** A leaderboard of `$romname`: its first player's score made `$days` days ago, the others' before. */
function cabinetCrowned(string $romname, array $pseudos, int $days = 1): Game
{
    $game = Game::factory()->create(['romname' => $romname, 'description' => strtoupper($romname)]);
    foreach ($pseudos as $index => $pseudo) {
        $player = Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
        leaderboardScore($game, $player, 10_000 - $index * 100, attributes: ['achieved_at' => now()->subDays($index === 0 ? $days : $days + 10)]);
    }

    return $game;
}

beforeEach(function () {
    $this->freezeSecond();
});

it('answers the champions with their game, its pictures and the avatars', function () {
    $game = cabinetCrowned('pengo', ['ACE', 'BOB', 'CAT'], days: 10);
    foreach (['marquee', 'flyer', 'logo'] as $type) {
        GameMedia::query()->create(['game_id' => $game->id, 'type' => $type, 'position' => 0, 'path' => "game-media/pengo/{$type}.png", 'mime' => 'image/png', 'hash' => str_repeat($type[0], 64)]);
    }
    cabinetCrowned('alone', ['DAN'], days: 90);
    $ace = Player::query()->where('pseudo_3', 'ACE')->firstOrFail();
    $ace->forceFill(['avatar_hash' => str_repeat('a', 64)])->save();

    $this->getJson('/api/v1/champions', cabinetHeaders(...cabinetWithToken()))->assertOk()
        ->assertExactJson(['champions' => [[
            'player' => ['id' => $ace->uuid, 'pseudo_3' => 'ACE', 'avatar' => str_repeat('a', 64)],
            'game' => [
                'romname' => 'pengo', 'description' => 'PENGO',
                'marquee' => str_repeat('m', 64), 'flyer' => str_repeat('f', 64), 'logo' => str_repeat('l', 64),
            ],
            'score' => 10_000,
            'players' => 3,
            'since' => now()->subDays(10)->toIso8601String(),
            'days' => 10,
        ]]]);
});

it('answers 10 champions, or as many as asked up to 30', function () {
    foreach (range(1, 12) as $index) {
        cabinetCrowned("game{$index}", ['A'.chr(64 + $index).'A', 'B'.chr(64 + $index).'B']);
    }
    $headers = cabinetHeaders(...cabinetWithToken());

    $this->getJson('/api/v1/champions', $headers)->assertOk()->assertJsonCount(10, 'champions');
    $this->getJson('/api/v1/champions?limit=12', $headers)->assertOk()->assertJsonCount(12, 'champions');
    $this->getJson('/api/v1/champions?limit=31', $headers)->assertUnprocessable();
});

it('needs the scores:read ability', function () {
    [$client, $token] = cabinetWithToken(['type' => ClientType::Service]);

    $this->getJson('/api/v1/champions', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});
