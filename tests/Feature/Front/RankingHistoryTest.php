<?php

use App\Models\Game;
use App\Models\Member;
use App\Models\Player;
use Illuminate\Support\Carbon;

// The places and points of the global podium's first players, day by day (docs/DECISIONS.md D97, D104).

beforeEach(fn () => $this->actingAs(Member::factory()->create(), 'member'));

/** A score of these initials on the game, made at this UTC time. */
function scoreAt(Game $game, string $pseudo, int $score, string $at): void
{
    $player = Player::query()->where('pseudo_3', $pseudo)->first() ?? publicPlayer($pseudo);
    leaderboardScore($game, $player, $score, attributes: ['achieved_at' => Carbon::parse($at, 'UTC')]);
}

/** @return array<string, array{rank: int, history: list<array{date: string, rank: int}>}> By initials. */
function rankingHistory(): array
{
    $players = test()->getJson('/api/v1/front/ranking/history')->assertOk()->json('players');

    return collect($players)->mapWithKeys(fn (array $entry): array => [$entry['player']['pseudo_3'] => ['rank' => $entry['rank'], 'history' => $entry['history']]])->all();
}

it('tells each place on the day it changed, the days in between left out', function () {
    $game = Game::factory()->create();
    scoreAt($game, 'AAA', 1000, '2026-10-01 18:00');
    scoreAt($game, 'BBB', 900, '2026-10-01 19:00');
    // A day when nothing moves.
    scoreAt($game, 'AAA', 950, '2026-10-02 18:00');
    scoreAt($game, 'BBB', 1100, '2026-10-03 18:00');

    expect(rankingHistory())->toBe([
        'BBB' => ['rank' => 1, 'history' => [['date' => '2026-10-01', 'rank' => 2], ['date' => '2026-10-03', 'rank' => 1]]],
        'AAA' => ['rank' => 2, 'history' => [['date' => '2026-10-01', 'rank' => 1], ['date' => '2026-10-03', 'rank' => 2]]],
    ]);
});

it('starts a player on the day it enters the ranking', function () {
    $game = Game::factory()->create();
    scoreAt($game, 'AAA', 1000, '2026-10-01 18:00');
    scoreAt($game, 'BBB', 900, '2026-10-01 19:00');
    scoreAt($game, 'CCC', 2000, '2026-10-05 18:00');

    expect(rankingHistory()['CCC']['history'])->toBe([['date' => '2026-10-05', 'rank' => 1]])
        ->and(rankingHistory()['BBB']['history'])->toBe([['date' => '2026-10-01', 'rank' => 2], ['date' => '2026-10-05', 'rank' => 3]]);
});

it('counts the days in the front readers\' timezone', function () {
    $game = Game::factory()->create();
    scoreAt($game, 'AAA', 1000, '2026-10-01 18:00');
    scoreAt($game, 'BBB', 900, '2026-10-01 19:00');
    // 00:30 in Paris, the next day.
    scoreAt($game, 'BBB', 1100, '2026-10-01 22:30');

    expect(rankingHistory()['BBB']['history'])->toBe([['date' => '2026-10-01', 'rank' => 2], ['date' => '2026-10-02', 'rank' => 1]]);
});

it('gives the first ten players only, with the places they held further down', function () {
    $game = Game::factory()->create();
    foreach (range(1, 12) as $index) {
        scoreAt($game, 'P'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 10_000 - $index * 100, '2026-10-01 18:00');
    }
    // The twelfth then beats everyone.
    scoreAt($game, 'P12', 20_000, '2026-10-02 18:00');

    $history = rankingHistory();

    expect($history)->toHaveCount(10)
        ->and($history)->not->toHaveKey('P10')
        ->and($history)->not->toHaveKey('P11')
        ->and($history['P12']['history'])->toBe([['date' => '2026-10-01', 'rank' => 12], ['date' => '2026-10-02', 'rank' => 1]]);
});

it('leaves hidden scores out of the past as well', function () {
    $game = Game::factory()->create();
    scoreAt($game, 'AAA', 1000, '2026-10-01 18:00');
    scoreAt($game, 'BBB', 900, '2026-10-01 19:00');
    scoreAt($game, 'BBB', 1100, '2026-10-02 18:00');
    Player::query()->where('pseudo_3', 'BBB')->first()->scores()->where('score', 1100)->update(['hidden_at' => now()]);

    expect(rankingHistory()['BBB']['history'])->toBe([['date' => '2026-10-01', 'rank' => 2]]);
});

it('tells the points on each day they changed, even when the place holds', function () {
    // Full points from two players: 100 for the first, 80 for the second.
    config(['hiscores.ranking.full_competition_players' => 2]);
    $first = Game::factory()->create();
    $second = Game::factory()->create();
    scoreAt($first, 'AAA', 1000, '2026-10-01 18:00');
    scoreAt($first, 'BBB', 900, '2026-10-01 19:00');
    // A day when nothing moves.
    scoreAt($first, 'AAA', 950, '2026-10-02 18:00');
    scoreAt($first, 'BBB', 1100, '2026-10-03 18:00');
    // BBB stays first and gains a game.
    scoreAt($second, 'BBB', 500, '2026-10-04 18:00');
    scoreAt($second, 'CCC', 400, '2026-10-04 19:00');

    $players = collect($this->getJson('/api/v1/front/ranking/history')->assertOk()->json('players'))->keyBy('player.pseudo_3');

    expect($players['BBB']['points'])->toBe(200)
        ->and($players['BBB']['history'])->toBe([['date' => '2026-10-01', 'rank' => 2], ['date' => '2026-10-03', 'rank' => 1]])
        ->and($players['BBB']['points_history'])->toBe([
            ['date' => '2026-10-01', 'points' => 80],
            ['date' => '2026-10-03', 'points' => 100],
            ['date' => '2026-10-04', 'points' => 200],
        ])
        ->and($players['AAA']['points_history'])->toBe([['date' => '2026-10-01', 'points' => 100], ['date' => '2026-10-03', 'points' => 80]])
        ->and($players['CCC']['points_history'])->toBe([['date' => '2026-10-04', 'points' => 80]]);
});

it('has an empty history before any score', function () {
    $this->getJson('/api/v1/front/ranking/history')->assertOk()->assertExactJson(['timezone' => 'Europe/Paris', 'players' => []]);
});

it('is for members only', function () {
    auth('member')->logout();

    $this->getJson('/api/v1/front/ranking/history')->assertUnauthorized();
});
