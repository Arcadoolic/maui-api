<?php

use App\Models\Game;
use App\Models\GameMedia;
use App\Models\Member;
use App\Models\Player;

// Champions: a first place and its game put forward, drawn for the day (docs/DECISIONS.md D96).

beforeEach(function () {
    // The dates the tests compare are to the second.
    $this->freezeSecond();
    $this->actingAs(Member::factory()->create(), 'member');
});

/**
 * A leaderboard of `$game`: its first player's score made `$days` days ago, the others' 10 days
 * before, each lower than the one before.
 *
 * @param  list<string>  $pseudos  First to last.
 */
function crowned(string $romname, array $pseudos, int $days = 1): Game
{
    $game = Game::factory()->create(['romname' => $romname, 'description' => strtoupper($romname)]);
    foreach ($pseudos as $index => $pseudo) {
        leaderboardScore($game, playerNamed($pseudo), 10_000 - $index * 100, attributes: ['achieved_at' => now()->subDays($index === 0 ? $days : $days + 10)]);
    }

    return $game;
}

function playerNamed(string $pseudo): Player
{
    return Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
}

/** @return list<string> The champions' initials, in order. */
function champions(): array
{
    return test()->getJson('/api/v1/front/crowns')->assertOk()->json('champions.*.player.pseudo_3');
}

it('puts forward a first place where others are ranked, with its game and how long it holds', function () {
    $game = crowned('pengo', ['ACE', 'BOB', 'CAT'], days: 10);
    foreach (['marquee', 'flyer', 'logo', 'screenshot'] as $type) {
        GameMedia::query()->create(['game_id' => $game->id, 'type' => $type, 'position' => 0, 'path' => "game-media/pengo/{$type}.png", 'mime' => 'image/png', 'hash' => str_repeat($type[0], 64)]);
    }
    crowned('alone', ['DAN'], days: 90);

    $this->getJson('/api/v1/front/crowns')->assertOk()
        ->assertJsonPath('champions.*.game.romname', ['pengo'])
        ->assertJsonPath('champions.0.player.pseudo_3', 'ACE')
        ->assertJsonPath('champions.0.game.description', 'PENGO')
        ->assertJsonPath('champions.0.game.marquee', str_repeat('m', 64))
        ->assertJsonPath('champions.0.game.flyer', str_repeat('f', 64))
        ->assertJsonPath('champions.0.game.logo', str_repeat('l', 64))
        ->assertJsonPath('champions.0.game.screenshot', str_repeat('s', 64))
        ->assertJsonPath('champions.0.score', 10_000)
        ->assertJsonPath('champions.0.players', 3)
        ->assertJsonPath('champions.0.days', 10)
        ->assertJsonPath('champions.0.since', now()->subDays(10)->utc()->toIso8601String());
});

it('holds a first place from the first score above the second, not from the last best', function () {
    $game = crowned('kept', ['ACE', 'BOB'], days: 1);
    leaderboardScore($game, playerNamed('ACE'), 9_950, attributes: ['achieved_at' => now()->subDays(8)]);
    leaderboardScore($game, playerNamed('ACE'), 9_000, attributes: ['achieved_at' => now()->subDays(30)]);

    $this->getJson('/api/v1/front/crowns')
        ->assertJsonPath('champions.0.days', 8)
        ->assertJsonPath('champions.0.score', 10_000)
        ->assertJsonPath('champions.0.game.marquee', null);
});

it('puts forward one first place of each player first, then the others', function () {
    crowned('a', ['ACE', 'ZED']);
    crowned('b', ['ACE', 'ZED']);
    crowned('c', ['ACE', 'ZED']);
    crowned('d', ['BOB', 'ZED']);

    expect(array_slice(champions(), 0, 2))->toEqualCanonicalizing(['ACE', 'BOB'])
        ->and(champions())->toHaveCount(4);
});

it('draws the same first places all day, and others on other days', function () {
    foreach (range(1, 12) as $index) {
        crowned('game'.$index, [sprintf('P%02d', $index), 'ZED']);
    }
    $this->travelTo(now()->startOfDay()->addHours(9));
    $morning = champions();
    $this->travelTo(now()->addHours(12));

    expect(champions())->toBe($morning);

    $days = collect(range(1, 10))->map(function () {
        $this->travelTo(now()->addDay());

        return implode(',', array_slice(champions(), 0, 3));
    });
    expect($days->unique()->count())->toBeGreaterThan(1);
});

it('draws more often the players the global podium leaves out', function () {
    config(['hiscores.crowns.podium_weight' => 0.02]);
    // ACE, BOB and CAT hold the global podium, DAN one first place over a single player.
    foreach (['a', 'b', 'c'] as $romname) {
        crowned($romname.'1', ['ACE', 'BOB', 'CAT', 'EVE', 'FAY']);
        crowned($romname.'2', ['BOB', 'CAT', 'ACE', 'EVE', 'FAY']);
        crowned($romname.'3', ['CAT', 'ACE', 'BOB', 'EVE', 'FAY']);
    }
    crowned('dan', ['DAN', 'GUS'], days: 10);

    $firsts = collect(range(1, 20))->map(function () {
        $this->travelTo(now()->addDay());

        return champions()[0];
    })->countBy();

    expect($firsts['DAN'] ?? 0)->toBeGreaterThan(10);
});

it('needs a member', function () {
    auth('member')->logout();

    $this->getJson('/api/v1/front/crowns')->assertUnauthorized();
});
