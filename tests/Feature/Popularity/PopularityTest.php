<?php

use App\Enums\PopularityLabel;
use App\Models\Client;
use App\Models\Game;
use App\Services\Popularity\GamePopularity;
use App\Services\Popularity\Popularity;

// Popularity of the games, from the cabinets' votes and plays (docs/DECISIONS.md D76).

/**
 * Opinions of the first cabinets of the fleet on a game, one [vote, plays, days since the last
 * play] each; the fleet grows to as many cabinets as needed.
 *
 * @param  list<array{0: int, 1: int, 2: int|null}>  $opinions
 */
function reported(Game $game, array $opinions): Game
{
    $cabinets = Client::query()->orderBy('id')->pluck('id')->all();
    foreach ($opinions as $index => [$vote, $plays, $daysAgo]) {
        $game->opinions()->create([
            'client_id' => $cabinets[$index] ?? Client::factory()->create()->id,
            'vote' => $vote,
            'play_count' => $plays,
            'last_played_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
        ]);
    }

    return $game;
}

/** A fleet of ten cabinets, so that the activity of the games under test is measured against it. */
function fleetOfTen(): void
{
    reported(Game::factory()->create(), array_fill(0, 10, [0, 0, null]));
}

function popularityOf(Game $game): GamePopularity
{
    return app(Popularity::class)->all()->get($game->id);
}

it('counts the votes, the plays and the cabinets of a game', function () {
    $game = reported(Game::factory()->create(), [[1, 10, 2], [1, 4, 90], [-1, 1, null], [0, 3, 5]]);
    leaderboardScore($game, publicPlayer('ACE'), 100);
    leaderboardScore($game, publicPlayer('BOB'), 90);

    $popularity = popularityOf($game);

    expect($popularity->thumbsUp)->toBe(2)
        ->and($popularity->thumbsDown)->toBe(1)
        ->and($popularity->votes())->toBe(3)
        ->and($popularity->cabinets)->toBe(4)
        ->and($popularity->plays)->toBe(18)
        ->and($popularity->recentCabinets)->toBe(2)
        ->and($popularity->rankedPlayers)->toBe(2);
});

it('draws the opinion of a game with few votes to the average of the fleet', function () {
    // The fleet: 8 thumbs up for 2 thumbs down on this one, 0.8 with the others' votes.
    $many = reported(Game::factory()->create(), [...array_fill(0, 7, [1, 1, null]), [-1, 1, null]]);
    $single = reported(Game::factory()->create(), [[1, 1, null]]);
    $bad = reported(Game::factory()->create(), [[-1, 1, null]]);

    // (ups + 3 x 0.8) / (votes + 3)
    expect(popularityOf($single)->opinion)->toBe(0.85)
        ->and(popularityOf($many)->opinion)->toBe(round((7 + 2.4) / 11, 4))
        ->and(popularityOf($bad)->opinion)->toBe(0.6)
        ->and(popularityOf($single)->opinion)->toBeLessThan(popularityOf($many)->opinion + 0.01);
});

it('gives a game nobody voted on the average opinion, sorted by its activity', function () {
    reported(Game::factory()->create(), [[1, 1, null], [-1, 1, null]]);
    $played = reported(Game::factory()->create(), [[0, 30, 1], [0, 20, 2]]);
    $tried = reported(Game::factory()->create(), [[0, 1, 200]]);

    expect(popularityOf($played)->opinion)->toBe(0.5)
        ->and(popularityOf($tried)->opinion)->toBe(0.5)
        ->and(popularityOf($played)->label)->toBe(PopularityLabel::Addictive)
        ->and(popularityOf($tried)->label)->toBeNull();
    expect(app(Popularity::class)->all()->keys()->search($played->id))
        ->toBeLessThan(app(Popularity::class)->all()->keys()->search($tried->id));
});

it('does not let one cabinet playing a lot weigh as much as many cabinets playing', function () {
    fleetOfTen();
    $one = reported(Game::factory()->create(), [[0, 500, 1]]);
    $many = reported(Game::factory()->create(), array_fill(0, 8, [0, 10, 1]));

    expect(popularityOf($one)->plays)->toBeGreaterThan(popularityOf($many)->plays)
        ->and(popularityOf($one)->activity)->toBeLessThan(popularityOf($many)->activity);
});

it('puts a liked and played game before a liked game nobody comes back to', function () {
    fleetOfTen();
    $hit = reported(Game::factory()->create(), array_fill(0, 8, [1, 30, 2]));
    $kept = reported(Game::factory()->create(), array_fill(0, 8, [1, 1, 200]));

    $ranking = app(Popularity::class)->all()->keys();

    expect(popularityOf($hit)->opinion)->toBe(popularityOf($kept)->opinion)
        ->and(popularityOf($hit)->label)->toBe(PopularityLabel::Hit)
        ->and(popularityOf($kept)->label)->toBe(PopularityLabel::HiddenGem)
        ->and($ranking->search($hit->id))->toBeLessThan($ranking->search($kept->id));
});

it('labels the games the cabinets disagree on, and the ones they all turned down', function () {
    fleetOfTen();
    $divisive = reported(Game::factory()->create(), [[1, 3, 5], [1, 2, 5], [-1, 1, 50], [-1, 1, 50]]);
    $missed = reported(Game::factory()->create(), [[-1, 1, 50], [-1, 1, 50], [-1, 2, 50], [0, 1, 50]]);
    $saved = reported(Game::factory()->create(), [[-1, 1, 50], [-1, 1, 50], [-1, 1, 50], [-1, 1, 50], [1, 1, 50]]);

    expect(popularityOf($divisive)->label)->toBe(PopularityLabel::Divisive)
        ->and(popularityOf($missed)->label)->toBe(PopularityLabel::MissedDate)
        ->and(popularityOf($saved)->label)->toBeNull();
});

it('gives no label about the votes under the minimum of votes', function () {
    fleetOfTen();
    $twoUp = reported(Game::factory()->create(), [[1, 1, 200], [1, 1, 200]]);
    $twoDown = reported(Game::factory()->create(), [[-1, 1, 200], [-1, 1, 200]]);

    expect(popularityOf($twoUp)->label)->toBeNull()
        ->and(popularityOf($twoDown)->label)->toBeNull();

    config(['hiscores.popularity.min_votes' => 2]);

    expect(popularityOf($twoUp)->label)->toBe(PopularityLabel::HiddenGem)
        ->and(popularityOf($twoDown)->label)->toBe(PopularityLabel::MissedDate);
});

it('measures the activity against the size of the fleet', function () {
    $game = reported(Game::factory()->create(), [[0, 20, 1], [0, 20, 1]]);
    $small = popularityOf($game)->activity;

    fleetOfTen();

    expect(popularityOf($game)->activity)->toBeLessThan($small);
});

it('knows a game from its scores alone', function () {
    $game = Game::factory()->create();
    leaderboardScore($game, publicPlayer('ACE'), 100);

    $popularity = popularityOf($game);

    expect($popularity->cabinets)->toBe(0)
        ->and($popularity->rankedPlayers)->toBe(1)
        ->and($popularity->activity)->toBeGreaterThan(0.0)
        ->and($popularity->label)->toBeNull();
});

it('leaves out the games nobody reported nor scored on', function () {
    $game = Game::factory()->create();

    expect(app(Popularity::class)->all()->has($game->id))->toBeFalse();
});
