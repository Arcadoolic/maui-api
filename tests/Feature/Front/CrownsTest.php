<?php

use App\Models\Game;
use App\Models\Member;
use App\Models\Player;

// The first places of the hiscores front: champions and unbeaten crowns (docs/DECISIONS.md D96).

beforeEach(function () {
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
        $player = Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
        leaderboardScore($game, $player, 10_000 - $index * 100, attributes: ['achieved_at' => now()->subDays($index === 0 ? $days : $days + 10)]);
    }

    return $game;
}

describe('champions', function () {
    it('ranks the players by the players their first places beat, a lone first place counting nothing', function () {
        crowned('a', ['ACE', 'BOB', 'CAT', 'DAN']);
        crowned('b', ['BOB', 'ACE']);
        crowned('c', ['BOB', 'CAT']);
        crowned('d', ['CAT']);

        $this->getJson('/api/v1/front/crowns')->assertOk()
            ->assertJsonPath('champions.*.player.pseudo_3', ['ACE', 'BOB'])
            ->assertJsonPath('champions.*.beaten', [3, 2])
            ->assertJsonPath('champions.*.crowns', [1, 2])
            ->assertJsonPath('champions.*.rank', [1, 2]);
    });

    it('puts the first to get there first at equal players beaten', function () {
        crowned('a', ['BOB', 'ACE'], days: 1);
        crowned('b', ['ACE', 'BOB'], days: 5);

        $this->getJson('/api/v1/front/crowns')->assertJsonPath('champions.*.player.pseudo_3', ['ACE', 'BOB']);
    });
});

describe('unbeaten', function () {
    it('ranks the first places by the players they beat times the days they hold', function () {
        crowned('long', ['ACE', 'BOB', 'CAT'], days: 10);
        crowned('crowd', ['BOB', 'ACE', 'CAT', 'DAN', 'EVE'], days: 2);
        crowned('alone', ['CAT'], days: 90);

        $this->getJson('/api/v1/front/crowns')->assertOk()
            ->assertJsonPath('unbeaten.*.game.romname', ['long', 'crowd'])
            ->assertJsonPath('unbeaten.*.player.pseudo_3', ['ACE', 'BOB'])
            ->assertJsonPath('unbeaten.0.players', 3)
            ->assertJsonPath('unbeaten.0.score', 10_000)
            ->assertJsonPath('unbeaten.0.days', 10)
            ->assertJsonPath('unbeaten.0.since', now()->subDays(10)->utc()->toIso8601String());
    });

    it('holds a first place from the first score above the second, not from the last best', function () {
        $game = crowned('kept', ['ACE', 'BOB'], days: 1);
        leaderboardScore($game, Player::query()->where('pseudo_3', 'ACE')->firstOrFail(), 9_950, attributes: ['achieved_at' => now()->subDays(8)]);
        leaderboardScore($game, Player::query()->where('pseudo_3', 'ACE')->firstOrFail(), 9_000, attributes: ['achieved_at' => now()->subDays(30)]);

        $this->getJson('/api/v1/front/crowns')
            ->assertJsonPath('unbeaten.0.days', 8)
            ->assertJsonPath('unbeaten.0.score', 10_000);
    });
});

it('gives three of each', function () {
    foreach (range(1, 4) as $index) {
        crowned('g'.$index, ['P0'.$index, 'ZED']);
    }

    $this->getJson('/api/v1/front/crowns')
        ->assertJsonCount(3, 'champions')
        ->assertJsonCount(3, 'unbeaten');
});

it('needs a member', function () {
    auth('member')->logout();

    $this->getJson('/api/v1/front/crowns')->assertUnauthorized();
});
