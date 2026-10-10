<?php

use App\Models\Client;
use App\Models\Game;
use App\Models\Member;

// Popularity of the games as the hiscores front reads it (docs/DECISIONS.md D76, D77).

beforeEach(function () {
    $this->member = Member::factory()->create();
    $this->actingAs($this->member, 'member');
    // A fleet of ten cabinets, the ones the opinions below come from.
    $this->cabinets = Client::factory()->count(10)->create();
});

/**
 * Opinions of the first cabinets of the fleet on a game: one [vote, plays, days since the last play] each.
 *
 * @param  list<array{0: int, 1: int, 2: int|null}>  $opinions
 */
function fleetReported(Game $game, array $opinions): Game
{
    foreach ($opinions as $index => [$vote, $plays, $daysAgo]) {
        $game->opinions()->create([
            'client_id' => test()->cabinets[$index]->id,
            'vote' => $vote,
            'play_count' => $plays,
            'last_played_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
        ]);
    }

    return $game;
}

function frontGame(string $romname, string $description): Game
{
    return Game::factory()->create(['romname' => $romname, 'description' => $description]);
}

describe('game list', function () {
    it('gives the popularity of each game, null when nobody reported it', function () {
        fleetReported(frontGame('hit', 'A'), array_fill(0, 8, [1, 30, 2]));
        frontGame('quiet', 'B');

        $this->getJson('/api/v1/front/games')->assertOk()
            ->assertJsonPath('games.0.popularity.label', 'hit')
            ->assertJsonPath('games.0.popularity.index', fn (mixed $index): bool => $index > 70)
            ->assertJsonPath('games.1.popularity', null);
    });

    it('sorts by popularity, the games nobody reported last', function () {
        frontGame('quiet', 'A');
        fleetReported(frontGame('kept', 'B'), array_fill(0, 8, [1, 1, 200]));
        fleetReported(frontGame('hit', 'C'), array_fill(0, 8, [1, 30, 2]));
        fleetReported(frontGame('missed', 'D'), array_fill(0, 4, [-1, 1, 200]));

        $this->getJson('/api/v1/front/games?sort=popularity')
            ->assertJsonPath('games.*.romname', ['hit', 'kept', 'missed', 'quiet']);
    });

    it('filters by label', function () {
        fleetReported(frontGame('kept', 'A'), array_fill(0, 8, [1, 1, 200]));
        fleetReported(frontGame('hit', 'B'), array_fill(0, 8, [1, 30, 2]));

        $this->getJson('/api/v1/front/games?label=hidden_gem')
            ->assertJsonPath('games.*.romname', ['kept'])
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/front/games?label=best')->assertUnprocessable();
    });

    it('offers the labels the listed games have, in a fixed order', function () {
        fleetReported(frontGame('missed', 'A'), array_fill(0, 4, [-1, 1, 200]));
        fleetReported(frontGame('hit', 'B'), array_fill(0, 8, [1, 30, 2]));
        // Not listed: no cabinet can read its hiscores (D74).
        fleetReported(Game::factory()->withoutHiscores()->create(), [[1, 2, 5], [1, 2, 5], [-1, 1, 50], [-1, 1, 50]]);

        $this->getJson('/api/v1/front/games/filters')->assertJsonPath('labels', ['hit', 'missed_date']);
    });
});

describe('game page', function () {
    it('says how many cabinets liked the game and how much it is played', function () {
        // The whole fleet reports something: the activity below is measured against ten cabinets.
        fleetReported(frontGame('other', 'Other'), array_fill(0, 10, [0, 1, null]));
        fleetReported(frontGame('dkong', 'Donkey Kong'), [[1, 10, 2], [1, 4, 90], [-1, 1, null], [0, 3, 5]]);

        $this->getJson('/api/v1/front/games/dkong')->assertOk()
            ->assertJsonPath('popularity.thumbs_up', 2)
            ->assertJsonPath('popularity.votes', 3)
            ->assertJsonPath('popularity.cabinets', 4)
            ->assertJsonPath('popularity.plays', 18)
            ->assertJsonPath('popularity.label', null)
            ->assertJsonMissingPath('popularity.thumbs_down');
    });

    it('has no popularity for a game nobody reported', function () {
        frontGame('dkong', 'Donkey Kong');

        $this->getJson('/api/v1/front/games/dkong')->assertJsonPath('popularity', null);
    });
});

describe('highlights', function () {
    it('puts forward the liked games my player has no score on, the most popular first', function () {
        $mine = publicPlayer('ACE');
        $this->member->players()->attach($mine, ['linked_at' => now()]);
        $played = fleetReported(frontGame('played', 'A'), array_fill(0, 8, [1, 40, 1]));
        leaderboardScore($played, $mine, 100);
        fleetReported(frontGame('kept', 'B'), array_fill(0, 8, [1, 1, 200]));
        $hit = fleetReported(frontGame('hit', 'C'), array_fill(0, 8, [1, 30, 2]));
        leaderboardScore($hit, publicPlayer('BOB'), 100);
        fleetReported(frontGame('missed', 'D'), array_fill(0, 4, [-1, 1, 200]));
        fleetReported(Game::factory()->withoutHiscores()->create(), array_fill(0, 8, [1, 30, 2]));

        $this->getJson('/api/v1/front/games/highlights')->assertOk()
            ->assertJsonPath('discover.*.romname', ['hit', 'kept'])
            ->assertJsonPath('discover.0.popularity.label', 'hit')
            ->assertJsonPath('discover.0.ranked_players', 1);
        $this->getJson('/api/v1/front/games/highlights?limit=1')->assertJsonPath('discover.*.romname', ['hit']);
    });

    it('puts forward every liked game to a member without a player', function () {
        fleetReported(frontGame('hit', 'A'), array_fill(0, 8, [1, 30, 2]));

        $this->getJson('/api/v1/front/games/highlights')->assertJsonPath('discover.*.romname', ['hit']);
    });

    it('lists the games most played and scored on lately', function () {
        fleetReported(frontGame('old', 'A'), array_fill(0, 8, [1, 30, 40]));
        fleetReported(frontGame('week', 'B'), array_fill(0, 3, [0, 5, 2]));
        $scored = fleetReported(frontGame('scored', 'C'), array_fill(0, 3, [0, 5, 3]));
        leaderboardScore($scored, publicPlayer('ACE'), 100, attributes: ['achieved_at' => now()->subDay()]);
        leaderboardScore(frontGame('former', 'D'), publicPlayer('BOB'), 100, attributes: ['achieved_at' => now()->subDays(60)]);

        $this->getJson('/api/v1/front/games/highlights')
            ->assertJsonPath('trending.*.romname', ['scored', 'week']);
    });

    it('checks the limit', function () {
        $this->getJson('/api/v1/front/games/highlights?limit=0')->assertUnprocessable();
        $this->getJson('/api/v1/front/games/highlights?limit=25')->assertUnprocessable();
    });

    it('needs a member', function () {
        auth('member')->logout();

        $this->getJson('/api/v1/front/games/highlights')->assertUnauthorized();
    });
});

describe('missed dates', function () {
    it('lists the games every cabinet that voted turned down, the unlisted ones included', function () {
        fleetReported(frontGame('four', 'B'), [...array_fill(0, 4, [-1, 1, 200]), [0, 1, 200]]);
        fleetReported(Game::factory()->withoutHiscores()->create(['romname' => 'six', 'description' => 'A']), array_fill(0, 6, [-1, 1, 200]));
        fleetReported(Game::factory()->uncatalogued()->create(['romname' => 'bare']), array_fill(0, 4, [-1, 1, 200]));
        fleetReported(frontGame('two', 'C'), array_fill(0, 2, [-1, 1, 200]));
        fleetReported(frontGame('hit', 'D'), array_fill(0, 8, [1, 30, 2]));

        $this->getJson('/api/v1/front/games/missed-dates')->assertOk()
            ->assertJsonPath('missed.*.romname', ['six', 'four', 'bare'])
            ->assertJsonPath('missed.*.votes', [6, 4, 4])
            ->assertJsonPath('missed.*.listed', [false, true, false])
            ->assertJsonPath('saved', [])
            ->assertJsonPath('min_votes', 3);
    });

    it('sets apart the games a single cabinet saved', function () {
        fleetReported(frontGame('saved', 'A'), [...array_fill(0, 4, [-1, 1, 200]), [1, 3, 5]]);
        fleetReported(frontGame('shared', 'B'), [...array_fill(0, 3, [-1, 1, 200]), [1, 3, 5], [1, 3, 5]]);

        $this->getJson('/api/v1/front/games/missed-dates')
            ->assertJsonPath('missed', [])
            ->assertJsonPath('saved.*.romname', ['saved'])
            ->assertJsonPath('saved.0.votes', 5);
    });

    it('needs a member', function () {
        auth('member')->logout();

        $this->getJson('/api/v1/front/games/missed-dates')->assertUnauthorized();
    });
});
