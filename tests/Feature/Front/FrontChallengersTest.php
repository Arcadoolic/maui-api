<?php

use App\Models\Client;
use App\Models\Game;
use App\Models\Member;
use App\Models\Player;

// "Waiting for challengers": the games a single player is ranked on (docs/DECISIONS.md D95).

beforeEach(function () {
    $this->member = Member::factory()->create();
    $this->actingAs($this->member, 'member');
});

/** A game only `$player` is ranked on, its score made `$daysAgo` days ago, the game on `$cabinets` cabinets. */
function waitingOn(string $romname, Player $player, int $score = 1000, int $daysAgo = 1, int $cabinets = 1): Game
{
    $game = Game::factory()->create(['romname' => $romname, 'description' => strtoupper($romname)]);
    foreach (range(1, $cabinets) as $ignored) {
        $game->opinions()->create(['client_id' => Client::factory()->create()->id, 'vote' => 0, 'play_count' => 1]);
    }
    leaderboardScore($game, $player, $score, attributes: ['achieved_at' => now()->subDays($daysAgo)]);

    return $game;
}

/** @return list<string> */
function challengers(int $limit = 6): array
{
    return test()->getJson('/api/v1/front/games/highlights?limit='.$limit)->assertOk()->json('challengers.*.romname');
}

it('puts forward the games a single player waits on, with the score to beat', function () {
    $mine = publicPlayer('NOB');
    $this->member->players()->attach($mine, ['linked_at' => now()]);
    $bnj = publicPlayer('BNJ');
    waitingOn('alone', $bnj, 125400, daysAgo: 5);
    $pair = waitingOn('pair', $bnj);
    leaderboardScore($pair, publicPlayer('RAF'), 900);
    // Nobody challenges themselves.
    waitingOn('mine', $mine);

    $this->getJson('/api/v1/front/games/highlights')->assertOk()
        ->assertJsonPath('challengers.*.romname', ['alone'])
        ->assertJsonPath('challengers.0.challenge.player.pseudo_3', 'BNJ')
        ->assertJsonPath('challengers.0.challenge.score', 125400)
        ->assertJsonPath('challengers.0.challenge.since', now()->subDays(5)->utc()->toIso8601String())
        ->assertJsonPath('challengers.0.ranked_players', 1);
});

it('puts forward two games of the same player first, then fills the row with the others', function () {
    $bnj = publicPlayer('BNJ');
    foreach (['a', 'b', 'c', 'd'] as $romname) {
        waitingOn($romname, $bnj);
    }
    waitingOn('e', publicPlayer('RAF'));

    expect(challengers(3))->toHaveCount(3)->toContain('e')
        ->and(challengers())->toHaveCount(5);
});

it('draws the same games all day, and others on other days', function () {
    foreach (range(1, 12) as $index) {
        waitingOn('game'.$index, publicPlayer(sprintf('P%02d', $index)));
    }
    $this->travelTo(now()->startOfDay()->addHours(9));
    $morning = challengers(3);
    $this->travelTo(now()->addHours(12));

    expect(challengers(3))->toBe($morning);

    $days = collect(range(1, 10))->map(function () {
        $this->travelTo(now()->addDay());

        return implode(',', challengers(3));
    });
    expect($days->unique()->count())->toBeGreaterThan(1);
});

it('draws more often a game more cabinets can play, waiting on a fresher score', function () {
    waitingOn('fresh', publicPlayer('BNJ'), daysAgo: 1, cabinets: 8);
    waitingOn('stale', publicPlayer('RAF'), daysAgo: 90, cabinets: 1);

    $firsts = collect(range(1, 20))->map(function () {
        $this->travelTo(now()->addDay());

        return challengers(1)[0];
    })->countBy();

    expect($firsts['fresh'] ?? 0)->toBeGreaterThan(15);
});
