<?php

use App\Models\Game;
use App\Models\GameOpinion;
use Illuminate\Testing\TestResponse;

// Votes and play counts reported by the cabinets (docs/DECISIONS.md D75).

/**
 * One game of a PUT /opinions report.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function opinionPayload(array $overrides = []): array
{
    return [
        'romname' => 'dkong',
        'vote' => 1,
        'play_count' => 12,
        'last_played_at' => now()->subHour()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $opinions
 * @param  array<string, string>|null  $headers
 */
function putOpinions(array $opinions, ?array $headers = null): TestResponse
{
    if ($headers === null) {
        [$client, $token] = cabinetWithToken();
        $headers = cabinetHeaders($client, $token);
    }

    return test()->putJson('/api/v1/opinions', ['opinions' => $opinions], $headers);
}

it('stores the vote and the plays of each game, for the calling cabinet', function () {
    $game = Game::factory()->create(['romname' => 'dkong']);
    [$client, $token] = cabinetWithToken();

    putOpinions([opinionPayload(), opinionPayload(['romname' => 'hasamu', 'vote' => -1, 'play_count' => 1])], cabinetHeaders($client, $token))
        ->assertOk()
        ->assertExactJson(['received' => 2, 'created' => 2, 'updated' => 0, 'unchanged' => 0]);

    $opinion = GameOpinion::query()->where('game_id', $game->id)->sole();
    expect($opinion->client_id)->toBe($client->id)
        ->and($opinion->vote)->toBe(1)
        ->and($opinion->play_count)->toBe(12)
        ->and($opinion->last_played_at)->not->toBeNull()
        ->and($opinion->voted_at)->not->toBeNull();
});

it('creates a bare game for a romname outside the catalog', function () {
    putOpinions([opinionPayload(['romname' => 'hasamu', 'vote' => -1])])->assertOk();

    $game = Game::query()->where('romname', 'hasamu')->sole();
    expect($game->isCatalogued())->toBeFalse()
        ->and($game->description)->toBe('hasamu')
        ->and($game->opinions()->sole()->vote)->toBe(-1);
});

it('replaces what the cabinet had reported, and changes nothing on a resend', function () {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token);
    $payload = opinionPayload();
    putOpinions([$payload], $headers)->assertOk();

    putOpinions([$payload], $headers)
        ->assertExactJson(['received' => 1, 'created' => 0, 'updated' => 0, 'unchanged' => 1]);
    putOpinions([[...$payload, 'vote' => -1, 'play_count' => 13]], $headers)
        ->assertExactJson(['received' => 1, 'created' => 0, 'updated' => 1, 'unchanged' => 0]);

    $opinion = GameOpinion::query()->sole();
    expect($opinion->vote)->toBe(-1)->and($opinion->play_count)->toBe(13);
});

it('dates the vote when it changes, and forgets the date of a neutral one', function () {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token);

    $this->travelTo('2026-10-01 10:00:00');
    putOpinions([opinionPayload(['last_played_at' => null])], $headers)->assertOk();
    $this->travelTo('2026-10-02 10:00:00');
    putOpinions([opinionPayload(['last_played_at' => null, 'play_count' => 20])], $headers)->assertOk();
    expect(GameOpinion::query()->sole()->voted_at?->toDateString())->toBe('2026-10-01');

    putOpinions([opinionPayload(['last_played_at' => null, 'vote' => 0])], $headers)->assertOk();
    expect(GameOpinion::query()->sole()->voted_at)->toBeNull();
});

it('keeps one opinion per cabinet', function () {
    putOpinions([opinionPayload()])->assertOk();
    putOpinions([opinionPayload(['vote' => -1])])->assertOk();

    expect(GameOpinion::query()->orderBy('id')->pluck('vote')->all())->toBe([1, -1]);
});

it('rejects a malformed report as a whole', function (array $opinion) {
    putOpinions([opinionPayload(['romname' => 'pong']), $opinion])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed');

    expect(GameOpinion::query()->count())->toBe(0);
})->with([
    'a vote out of range' => [['romname' => 'dkong', 'vote' => 2, 'play_count' => 1]],
    'a negative play count' => [['romname' => 'dkong', 'vote' => 0, 'play_count' => -1]],
    'no vote' => [['romname' => 'dkong', 'play_count' => 1]],
    'a bad romname' => [['romname' => 'Donkey Kong', 'vote' => 0, 'play_count' => 1]],
    'a game twice' => [['romname' => 'pong', 'vote' => 0, 'play_count' => 1]],
    'a play in the future' => [['romname' => 'dkong', 'vote' => 0, 'play_count' => 1, 'last_played_at' => '2999-01-01T00:00:00Z']],
]);

it('refuses a report of more than 500 games', function () {
    $opinions = array_map(fn (int $index): array => opinionPayload(['romname' => 'game'.$index]), range(1, 501));

    putOpinions($opinions)->assertUnprocessable();
});

it('is for cabinets only', function () {
    [$service, $token] = serviceWithToken();

    putOpinions([opinionPayload()], serviceHeaders($service, $token))->assertForbidden();
    $this->putJson('/api/v1/opinions', ['opinions' => [opinionPayload()]])->assertUnauthorized();
});
