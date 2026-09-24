<?php

use App\Models\ClientStartup;

describe('GET /ping', function () {
    it('returns the client identity, the binding and the server time', function () {
        $this->freezeSecond();
        [$client, $token] = cabinetWithToken(['name' => 'marvelous_mario']);

        $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertExactJson([
                'client' => [
                    'key' => $client->public_key,
                    'name' => 'marvelous_mario',
                ],
                'machine' => [
                    'bound_at' => now()->toIso8601ZuluString(),
                    'newly_bound' => true,
                ],
                'server_time' => now()->toIso8601ZuluString(),
            ]);
    });
});

describe('POST /startups', function () {
    $validStartup = fn () => [
        'mame_version' => '0.272',
        'maui_version' => '2.4.0',
        'os' => 'linux',
        'os_version' => '6.8.0-139-generic',
        'client_datetime' => '2026-09-23T20:15:00+02:00',
    ];

    it('records the startup and returns its id', function () use ($validStartup) {
        $this->freezeSecond();
        [$client, $token] = cabinetWithToken();

        $response = $this->postJson('/api/v1/startups', $validStartup(), cabinetHeaders($client, $token))
            ->assertCreated()
            ->assertJsonPath('received_at', now()->toIso8601ZuluString());

        $startup = ClientStartup::findOrFail($response->json('id'));
        expect($startup->client_id)->toBe($client->id)
            ->and($startup->mame_version)->toBe('0.272')
            ->and($startup->os)->toBe('linux')
            ->and($startup->client_datetime->toIso8601ZuluString())->toBe('2026-09-23T18:15:00Z');
    });

    it('counts as a heartbeat', function () use ($validStartup) {
        $this->freezeSecond();
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/startups', $validStartup(), cabinetHeaders($client, $token))->assertCreated();

        expect($client->fresh()->last_heartbeat_at->equalTo(now()))->toBeTrue();
    });

    it('becomes the latest startup of the cabinet', function () use ($validStartup) {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/startups', $validStartup(), cabinetHeaders($client, $token))->assertCreated();
        $latestId = $this->postJson('/api/v1/startups', [...$validStartup(), 'maui_version' => '2.5.0'], cabinetHeaders($client, $token))
            ->assertCreated()
            ->json('id');

        expect($client->fresh()->latestStartup->id)->toBe($latestId)
            ->and($client->fresh()->latestStartup->maui_version)->toBe('2.5.0');
    });

    it('accepts UTC and fractional seconds datetimes', function (string $datetime) use ($validStartup) {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/startups', [...$validStartup(), 'client_datetime' => $datetime], cabinetHeaders($client, $token))
            ->assertCreated();
    })->with([
        'UTC designator' => '2026-09-23T18:15:00Z',
        'milliseconds, JavaScript toISOString()' => '2026-09-23T18:15:00.123Z',
        'milliseconds with offset' => '2026-09-23T18:15:00.123+00:00',
        'microseconds' => '2026-09-23T18:15:00.123456+02:00',
    ]);

    it('ignores unknown fields', function () use ($validStartup) {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/startups', [...$validStartup(), 'future_field' => 'x'], cabinetHeaders($client, $token))
            ->assertCreated();
    });

    it('rejects an invalid startup report', function (string $field, mixed $value) use ($validStartup) {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/startups', [...$validStartup(), $field => $value], cabinetHeaders($client, $token))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => [$field]]);

        expect(ClientStartup::count())->toBe(0);
    })->with([
        'missing mame_version' => ['mame_version', null],
        'too long mame_version' => ['mame_version', str_repeat('1', 33)],
        'missing maui_version' => ['maui_version', null],
        'unknown os' => ['os', 'amiga'],
        'too long os_version' => ['os_version', str_repeat('1', 65)],
        'datetime without offset' => ['client_datetime', '2026-09-23T20:15:00'],
        'not a datetime' => ['client_datetime', 'yesterday'],
    ]);
});

describe('POST /heartbeat', function () {
    it('updates the last heartbeat and returns no content', function () {
        $this->freezeSecond();
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/heartbeat', [], cabinetHeaders($client, $token))->assertNoContent();

        expect($client->fresh()->last_heartbeat_at->equalTo(now()))->toBeTrue();
    });
});
