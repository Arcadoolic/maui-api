<?php

use App\Enums\ScoreAttribution;
use App\Enums\ScoreRecoveryStatus;
use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Models\ScoreEvent;
use App\Models\ScoreRecovery;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// A score the cabinet's file holds but MAUI-API never got, recovered on an
// administrator's request (docs/DECISIONS.md D93).

/**
 * @param  array<string, mixed>  $attributes
 */
function recoveryFor(Client $client, Player $player, array $attributes = []): ScoreRecovery
{
    return ScoreRecovery::factory()->for($client)->for($player)->create([
        'romname' => 'atetris', 'table' => 'default', 'score' => 250_000,
        'achieved_at' => now()->subDays(3), ...$attributes,
    ]);
}

/**
 * The score of a recovery, as MAUI sends it: its id is the recovery's.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function recoveredScore(ScoreRecovery $recovery, array $overrides = []): array
{
    return [
        'id' => $recovery->uuid,
        'recovery_id' => $recovery->uuid,
        'player_id' => $recovery->player->uuid,
        'romname' => $recovery->romname,
        'table' => $recovery->table,
        'score' => $recovery->score,
        'achieved_at' => now()->subMinute()->toIso8601String(),
        'attribution' => 'recovered',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $scores
 */
function sendScores(Client $client, string $token, array $scores): TestResponse
{
    return test()->postJson('/api/v1/scores', ['scores' => $scores], cabinetHeaders($client, $token));
}

describe('GET /score-recoveries', function () {
    it('lists the open requests of the cabinet only', function () {
        [$client, $token] = cabinetWithToken();
        [$other] = cabinetWithToken();
        $player = linkedPlayer($client);
        $open = recoveryFor($client, $player);
        recoveryFor($client, $player, ['status' => ScoreRecoveryStatus::NotFound, 'closed_at' => now()]);
        recoveryFor($client, $player, ['expires_at' => now()->subMinute()]);
        recoveryFor($other, linkedPlayer($other));

        test()->getJson('/api/v1/score-recoveries', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertExactJson(['recoveries' => [[
                'id' => $open->uuid,
                'player_id' => $player->uuid,
                'romname' => 'atetris',
                'table' => 'default',
                'score' => 250_000,
                'achieved_at' => $open->achieved_at->toIso8601ZuluString(),
            ]]]);
    });
});

describe('POST /scores with a recovery', function () {
    it('stores the score as recovered, at the date of the request, and closes it', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $recovery = recoveryFor($client, $player);

        sendScores($client, $token, [recoveredScore($recovery)])
            ->assertOk()
            ->assertExactJson(['results' => [['id' => $recovery->uuid, 'status' => 'accepted', 'best' => 250_000]]]);

        $score = Score::query()->sole();
        expect($score->attribution)->toBe(ScoreAttribution::Recovered)
            ->and($score->achieved_at->toIso8601String())->toBe($recovery->achieved_at->toIso8601String())
            ->and($score->integrity_verdict)->toBeNull()
            ->and($recovery->refresh()->status)->toBe(ScoreRecoveryStatus::Accepted)
            ->and($recovery->score_id)->toBe($score->id)
            ->and($recovery->closed_at)->not->toBeNull();
    });

    it('records the event without announcing it', function () {
        [$client, $token] = cabinetWithToken();
        // Events are only recorded for a public player's scores (D52, D60).
        $recovery = recoveryFor($client, linkedPlayer($client, ['is_public' => true]), ['achieved_at' => now()->subMinutes(10)]);

        sendScores($client, $token, [recoveredScore($recovery)])->assertOk();

        expect(ScoreEvent::query()->sole()->announceable)->toBeFalse();
    });

    it('answers a resend as accepted, without a second score', function () {
        [$client, $token] = cabinetWithToken();
        $recovery = recoveryFor($client, linkedPlayer($client));

        sendScores($client, $token, [recoveredScore($recovery)])->assertOk();
        sendScores($client, $token, [recoveredScore($recovery)])
            ->assertJsonPath('results.0.status', 'accepted');

        expect(Score::query()->count())->toBe(1);
    });

    it('closes the request as not improved under the player\'s best', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $recovery = recoveryFor($client, $player);
        Score::factory()->for($client)->for($player)->create(['game_id' => Game::factory()->create(['romname' => 'atetris'])->id, 'score' => 300_000]);

        sendScores($client, $token, [recoveredScore($recovery)])
            ->assertJsonPath('results.0.status', 'not_improved');

        expect($recovery->refresh()->status)->toBe(ScoreRecoveryStatus::NotImproved);
    });

    it('refuses a score that is not the one requested, and leaves the request open', function (Closure $payload) {
        [$client, $token] = cabinetWithToken();
        $recovery = recoveryFor($client, linkedPlayer($client));

        sendScores($client, $token, [$payload($recovery, $client)])
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'recovery_mismatch');

        expect(Score::query()->count())->toBe(0)
            ->and($recovery->refresh()->status)->toBe(ScoreRecoveryStatus::Pending);
    })->with([
        'another score' => [fn (ScoreRecovery $r) => recoveredScore($r, ['score' => 249_999])],
        'another game' => [fn (ScoreRecovery $r) => recoveredScore($r, ['romname' => 'tetris'])],
        'another table' => [fn (ScoreRecovery $r) => recoveredScore($r, ['table' => 'extra'])],
        'another player' => [fn (ScoreRecovery $r, Client $c) => recoveredScore($r, ['player_id' => linkedPlayer($c)->uuid])],
        'unknown request' => [fn (ScoreRecovery $r) => recoveredScore($r, ['id' => (string) Str::uuid(), 'recovery_id' => (string) Str::uuid()])],
        'recovered without request' => [fn (ScoreRecovery $r) => [...recoveredScore($r, ['id' => (string) Str::uuid()]), 'recovery_id' => null]],
    ]);

    it('refuses the request of another cabinet, or one that is closed', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        [$other] = cabinetWithToken();
        $player->clients()->attach($other, ['linked_at' => now()]);
        $foreign = recoveryFor($other, $player);
        $closed = recoveryFor($client, $player, ['status' => ScoreRecoveryStatus::NotFound, 'closed_at' => now()]);
        $expired = recoveryFor($client, $player, ['expires_at' => now()->subMinute()]);

        sendScores($client, $token, [recoveredScore($foreign), recoveredScore($closed), recoveredScore($expired)])
            ->assertJsonPath('results.0.code', 'recovery_mismatch')
            ->assertJsonPath('results.1.code', 'recovery_mismatch')
            ->assertJsonPath('results.2.code', 'recovery_mismatch');
    });
});

describe('POST /score-recoveries/{id}/result', function () {
    it('closes a request the cabinet could not find, with the table it read', function () {
        [$client, $token] = cabinetWithToken();
        $recovery = recoveryFor($client, linkedPlayer($client));
        $table = [['rank' => 1, 'score' => 100_000, 'name' => 'ABC']];

        test()->postJson("/api/v1/score-recoveries/{$recovery->uuid}/result", [
            'outcome' => 'not_found', 'reason' => 'not_in_table', 'table' => $table,
        ], cabinetHeaders($client, $token))->assertNoContent();

        $recovery->refresh();
        expect($recovery->status)->toBe(ScoreRecoveryStatus::NotFound)
            ->and($recovery->closed_at)->not->toBeNull()
            ->and($recovery->result)->toEqual(['reason' => 'not_in_table', 'table' => $table]);
    });

    it('answers 409 for a closed request and 404 for another cabinet\'s', function () {
        [$client, $token] = cabinetWithToken();
        [$other] = cabinetWithToken();
        $closed = recoveryFor($client, linkedPlayer($client), ['status' => ScoreRecoveryStatus::Accepted, 'closed_at' => now()]);
        $foreign = recoveryFor($other, linkedPlayer($other));
        $body = ['outcome' => 'not_found', 'reason' => 'not_in_table', 'table' => []];

        test()->postJson("/api/v1/score-recoveries/{$closed->uuid}/result", $body, cabinetHeaders($client, $token))
            ->assertConflict()->assertJsonPath('code', 'recovery_closed');
        test()->postJson("/api/v1/score-recoveries/{$foreign->uuid}/result", $body, cabinetHeaders($client, $token))
            ->assertNotFound()->assertJsonPath('code', 'recovery_not_found');
    });

    it('refuses an unknown reason', function () {
        [$client, $token] = cabinetWithToken();
        $recovery = recoveryFor($client, linkedPlayer($client));

        test()->postJson("/api/v1/score-recoveries/{$recovery->uuid}/result", [
            'outcome' => 'not_found', 'reason' => 'whatever', 'table' => [],
        ], cabinetHeaders($client, $token))->assertUnprocessable();
    });
});

it('expires the requests left open past their date', function () {
    [$client] = cabinetWithToken();
    $player = linkedPlayer($client);
    $stale = recoveryFor($client, $player, ['expires_at' => now()->subMinute()]);
    $open = recoveryFor($client, $player);

    test()->artisan('score-recoveries:expire')->expectsOutput('1 score recovery request(s) expired.')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(ScoreRecoveryStatus::Expired)
        ->and($stale->closed_at)->not->toBeNull()
        ->and($open->refresh()->status)->toBe(ScoreRecoveryStatus::Pending);
});
