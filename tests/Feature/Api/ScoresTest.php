<?php

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

// Scores sent by the cabinets: personal bests only (docs/DECISIONS.md D50).

/**
 * One score of a POST /scores batch.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scorePayload(Player $player, int $score, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'player_id' => $player->uuid,
        'romname' => 'dkong',
        'score' => $score,
        'rank_on_cabinet' => 1,
        'achieved_at' => now()->subMinute()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function startupAttributes(): array
{
    return [
        'mame_version' => '0.289', 'maui_version' => '2.5.0', 'os' => 'linux',
        'os_version' => '6.8.0', 'client_datetime' => now(), 'received_at' => now(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $scores
 */
function postScores(Client $client, string $token, array $scores): TestResponse
{
    return test()->postJson('/api/v1/scores', ['scores' => $scores], cabinetHeaders($client, $token));
}

describe('intake', function () {
    it('stores a first score and reports it as the best', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $game = Game::factory()->create(['romname' => 'dkong']);
        $payload = scorePayload($player, 12_300);

        postScores($client, $token, [$payload])
            ->assertOk()
            ->assertExactJson(['results' => [['id' => $payload['id'], 'status' => 'accepted', 'best' => 12_300]]]);

        $score = Score::query()->sole();
        expect($score->uuid)->toBe($payload['id'])
            ->and($score->player_id)->toBe($player->id)
            ->and($score->game_id)->toBe($game->id)
            ->and($score->client_id)->toBe($client->id)
            ->and($score->table)->toBe('default')
            ->and($score->score)->toBe(12_300)
            ->and($score->rank_on_cabinet)->toBe(1);
    });

    it('is idempotent by score id', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $payload = scorePayload($player, 12_300);

        postScores($client, $token, [$payload])->assertOk();
        postScores($client, $token, [$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.0.best', 12_300);

        expect(Score::query()->count())->toBe(1);
    });

    it('drops a score that does not beat the best of the player', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        postScores($client, $token, [scorePayload($player, 12_300)])->assertOk();
        postScores($client, $token, [scorePayload($player, 12_300), scorePayload($player, 9_000)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'not_improved')
            ->assertJsonPath('results.0.best', 12_300)
            ->assertJsonPath('results.1.status', 'not_improved');

        expect(Score::query()->count())->toBe(1);
    });

    it('stores a better score, even sent from another cabinet', function () {
        [$client, $token] = cabinetWithToken();
        [$other, $otherToken] = cabinetWithToken();
        $player = linkedPlayer($client);
        $player->clients()->attach($other, ['linked_at' => now()]);

        postScores($client, $token, [scorePayload($player, 12_300)])->assertOk();
        postScores($other, $otherToken, [scorePayload($player, 15_000)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.0.best', 15_000);

        expect(Score::query()->count())->toBe(2);
    });

    it('keeps the bests of each game and table apart', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        postScores($client, $token, [scorePayload($player, 12_300)])->assertOk();
        postScores($client, $token, [
            scorePayload($player, 5_000, ['romname' => 'galaga']),
            scorePayload($player, 5_000, ['table' => 'time']),
        ])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'accepted');
    });

    it('leaves hidden scores out of the best', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $game = Game::factory()->create(['romname' => 'dkong']);
        Score::factory()->hidden()->create([
            'player_id' => $player->id, 'game_id' => $game->id, 'client_id' => $client->id, 'score' => 999_999,
        ]);

        postScores($client, $token, [scorePayload($player, 12_300)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.0.best', 12_300);
    });

    it('creates a bare game for a romname outside the catalog', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        postScores($client, $token, [scorePayload($player, 100, ['romname' => 'newgame'])])->assertOk();

        $game = Game::query()->where('romname', 'newgame')->sole();
        expect($game->isCatalogued())->toBeFalse()
            ->and($game->description)->toBe('newgame');
    });

    it('accepts the scores of a private or locked player', function () {
        [$client, $token] = cabinetWithToken();
        $private = linkedPlayer($client, ['is_public' => false]);
        $locked = linkedPlayer($client);
        $locked->forceFill(['pin_locked_at' => now()])->save();

        postScores($client, $token, [scorePayload($private, 100), scorePayload($locked, 100)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'accepted');
    });

    it('keeps the startup only when it is one of this cabinet', function () {
        [$client, $token] = cabinetWithToken();
        [$other] = cabinetWithToken();
        $player = linkedPlayer($client);
        $own = $client->startups()->create(startupAttributes());
        $foreign = $other->startups()->create(startupAttributes());

        postScores($client, $token, [
            scorePayload($player, 100, ['startup_id' => $own->id]),
            scorePayload($player, 200, ['startup_id' => $foreign->id]),
        ])->assertOk();

        expect(Score::query()->orderBy('score')->pluck('client_startup_id')->all())->toBe([$own->id, null]);
    });
});

describe('rejections', function () {
    it('rejects, score by score, the players this cabinet does not know', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $stranger = Player::factory()->create();
        $ok = scorePayload($player, 100);
        $unknown = scorePayload($stranger, 100);

        postScores($client, $token, [$unknown, $ok])
            ->assertOk()
            ->assertExactJson(['results' => [
                ['id' => $unknown['id'], 'status' => 'rejected', 'code' => 'player_not_found'],
                ['id' => $ok['id'], 'status' => 'accepted', 'best' => 100],
            ]]);
    });

    it('rejects the scores of a disabled player', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $player->disable();

        postScores($client, $token, [scorePayload($player, 100)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'player_disabled');

        expect(Score::query()->count())->toBe(0);
    });

    it('rejects an id already used by another cabinet', function () {
        [$client, $token] = cabinetWithToken();
        [$other, $otherToken] = cabinetWithToken();
        $payload = scorePayload(linkedPlayer($client), 100);
        postScores($client, $token, [$payload])->assertOk();

        postScores($other, $otherToken, [[...$payload, 'player_id' => linkedPlayer($other)->uuid]])
            ->assertOk()
            ->assertJsonPath('results.0.code', 'id_conflict');
    });

    it('validates the batch', function (array $override) {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        postScores($client, $token, [scorePayload($player, 100, $override)])->assertUnprocessable();
    })->with([
        'id' => [['id' => 'not-a-uuid']],
        'romname' => [['romname' => 'Dkong!']],
        'score' => [['score' => -1]],
        'future' => [['achieved_at' => now()->addHour()->toIso8601String()]],
        'table' => [['table' => 'Not A Table']],
    ]);

    it('refuses more than 100 scores at once', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $scores = array_map(fn (int $i): array => scorePayload($player, $i), range(1, 101));

        postScores($client, $token, $scores)->assertUnprocessable();
    });

    it('needs the scores:write ability', function () {
        [$client, $token] = cabinetWithToken(['type' => ClientType::Service]);

        $this->postJson('/api/v1/scores', ['scores' => []], cabinetHeaders($client, $token))
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    });
});
