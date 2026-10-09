<?php

use App\Models\Game;
use App\Models\Member;
use App\Models\Player;
use App\Services\Leaderboards\GlobalRanking;
use App\Services\Leaderboards\Standing;

// The global podium of the hiscores front (docs/DECISIONS.md D70).

/** A leaderboard with these players, first to last. */
function board(Game $game, array $pseudos, string $table = 'default'): void
{
    foreach (array_values($pseudos) as $index => $pseudo) {
        $player = Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
        leaderboardScore($game, $player, 10_000 - $index * 100, attributes: ['table' => $table]);
    }
}

/** @return array<string, Standing> Standings by initials. */
function standings(): array
{
    $names = Player::query()->pluck('pseudo_3', 'id');

    return app(GlobalRanking::class)->standings()->keyBy(fn (Standing $standing): string => $names[$standing->playerId])->all();
}

describe('points of a rank', function () {
    it('gives nothing to a player alone on a game, and full points from five players', function () {
        expect(GlobalRanking::points(1, 1))->toBe(0)
            ->and(GlobalRanking::points(1, 2))->toBe(25)
            ->and(GlobalRanking::points(1, 3))->toBe(50)
            ->and(GlobalRanking::points(1, 5))->toBe(100)
            ->and(GlobalRanking::points(1, 30))->toBe(100)
            ->and(GlobalRanking::points(2, 5))->toBe(80)
            ->and(GlobalRanking::points(3, 5))->toBe(65);
    });

    it('follows the configured number of players', function () {
        config(['hiscores.ranking.full_competition_players' => 7]);

        expect(GlobalRanking::points(1, 2))->toBe(17)
            ->and(GlobalRanking::points(1, 5))->toBe(67)
            ->and(GlobalRanking::points(1, 7))->toBe(100);
    });

    it('goes on beyond the scale, two points less per rank, five at least', function () {
        expect(GlobalRanking::points(9, 20))->toBe(20)
            ->and(GlobalRanking::points(10, 20))->toBe(18)
            ->and(GlobalRanking::points(17, 20))->toBe(5)
            ->and(GlobalRanking::points(40, 50))->toBe(5);
    });
});

describe('standings', function () {
    it('does not reward leading games nobody else plays', function () {
        // LON leads five games alone; ACE leads one game against six others.
        foreach (range(1, 5) as $ignored) {
            board(Game::factory()->create(), ['LON']);
        }
        board(Game::factory()->create(), ['ACE', 'BOB', 'CAT', 'DAN', 'EVE', 'FAY', 'GUS']);

        $standings = standings();

        expect($standings['ACE']->rank)->toBe(1)
            ->and($standings['ACE']->points)->toBe(100)
            ->and($standings['BOB']->points)->toBe(80)
            ->and($standings['LON']->points)->toBe(0)
            ->and($standings['LON']->rank)->toBe(8)
            // The former rule put LON first by far.
            ->and($standings['LON']->formerPoints)->toBe(2500)
            ->and($standings['ACE']->formerPoints)->toBe(500);
    });

    it('counts the best results only', function () {
        config(['hiscores.ranking.best_results' => 2]);
        board(Game::factory()->create(), ['ACE', 'B01', 'B02', 'B03', 'B04', 'B05', 'B06']);
        board(Game::factory()->create(), ['B01', 'ACE', 'B02', 'B03', 'B04', 'B05', 'B06']);
        board(Game::factory()->create(), ['B01', 'B02', 'ACE', 'B03', 'B04', 'B05', 'B06']);

        $ace = standings()['ACE'];

        expect($ace->points)->toBe(180)
            ->and($ace->games)->toBe(3)
            ->and(array_column($ace->results, 'points'))->toBe([100, 80, 65])
            ->and(array_column($ace->results, 'counted'))->toBe([true, true, false]);
    });

    it('counts a game once, by its best table', function () {
        $game = Game::factory()->create();
        board($game, ['B01', 'ACE', 'B02', 'B03', 'B04', 'B05', 'B06']);
        board($game, ['ACE', 'B01', 'B02', 'B03', 'B04', 'B05', 'B06'], 'hard');

        $ace = standings()['ACE'];

        expect($ace->points)->toBe(100)
            ->and($ace->games)->toBe(1)
            ->and($ace->results[0]['table'])->toBe('hard');
    });

    it('breaks a tie by crowns', function () {
        // Both get 50 points: ACE with two crowns of two players, BOB twice second of three.
        board(Game::factory()->create(), ['ACE', 'X01']);
        board(Game::factory()->create(), ['ACE', 'X02']);
        board(Game::factory()->create(), ['X03', 'BOB', 'X04']);
        board(Game::factory()->create(), ['X05', 'BOB', 'X06']);
        config(['hiscores.ranking.base' => [100, 50]]);

        $standings = standings();

        expect($standings['ACE']->points)->toBe($standings['BOB']->points)
            ->and($standings['ACE']->rank)->toBeLessThan($standings['BOB']->rank);
    });

    it('leaves private players out', function () {
        board(Game::factory()->create(), ['ACE', 'BOB']);
        leaderboardScore(Game::query()->firstOrFail(), Player::factory()->create(['pseudo_3' => 'PRV']), 99_999);

        expect(array_keys(standings()))->toBe(['ACE', 'BOB']);
    });
});

describe('front', function () {
    beforeEach(fn () => $this->actingAs(Member::factory()->create(), 'member'));

    it('serves the podium with its rule', function () {
        board(Game::factory()->create(), ['ACE', 'BOB', 'CAT', 'DAN', 'EVE', 'FAY', 'GUS']);

        $response = $this->getJson('/api/v1/front/ranking')->assertOk();

        expect($response->json('rules'))->toBe([
            'base' => [100, 80, 65, 55, 45, 38, 32, 26, 20], 'step' => 2, 'floor' => 5,
            'full_competition_players' => 5, 'best_results' => 15,
        ])
            ->and($response->json('ranking.*.player.pseudo_3'))->toBe(['ACE', 'BOB', 'CAT', 'DAN', 'EVE', 'FAY', 'GUS'])
            ->and($response->json('ranking.0'))->toMatchArray(['rank' => 1, 'points' => 100, 'counted' => 1, 'games' => 1, 'crowns' => 1, 'podiums' => 1])
            ->and($response->json('ranking.6'))->toMatchArray(['rank' => 7, 'points' => 32, 'crowns' => 0, 'podiums' => 0]);
    });

    it('tells a player its points, its global rank and what each game brings', function () {
        config(['hiscores.ranking.best_results' => 1]);
        $one = Game::factory()->create(['description' => 'A']);
        $two = Game::factory()->create(['description' => 'B']);
        board($one, ['ACE', 'BOB', 'CAT', 'DAN', 'EVE', 'FAY', 'GUS']);
        board($two, ['BOB', 'ACE']);
        $ace = Player::query()->where('pseudo_3', 'ACE')->firstOrFail();

        $this->getJson('/api/v1/front/players/'.$ace->uuid)->assertOk()
            ->assertJsonPath('stats.points', 100)
            ->assertJsonPath('stats.global_rank', 1)
            ->assertJsonPath('bests.0.points', 100)->assertJsonPath('bests.0.counted', true)
            ->assertJsonPath('bests.1.points', 20)->assertJsonPath('bests.1.counted', false);
    });

    it('has no points for a private player', function () {
        $private = Player::factory()->create();
        auth('member')->user()->players()->attach($private, ['linked_at' => now()]);
        leaderboardScore(Game::factory()->create(), $private, 100);

        $this->getJson('/api/v1/front/players/'.$private->uuid)->assertOk()
            ->assertJsonPath('stats.points', 0)
            ->assertJsonPath('stats.global_rank', null)
            ->assertJsonPath('bests.0.points', null)->assertJsonPath('bests.0.counted', false);
    });
});

describe('hiscores:ranking', function () {
    it('prints the podium next to the former rule, with other settings on demand', function () {
        board(Game::factory()->create(), ['ACE', 'BOB']);

        $this->artisan('hiscores:ranking')
            ->expectsOutputToContain('Best 15 results, full points from 5 ranked players.')
            ->expectsOutputToContain('ACE')
            ->assertSuccessful();
        $this->artisan('hiscores:ranking', ['--best' => 5, '--full' => 3])
            ->expectsOutputToContain('Best 5 results, full points from 3 ranked players.')
            ->assertSuccessful();
    });
});
