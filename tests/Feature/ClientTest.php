<?php

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use App\Services\ClientTokenIssuer;

it('generates a public key with the mk_ prefix', function () {
    expect(Client::factory()->create()->public_key)->toMatch('/^mk_[A-Za-z0-9]{24}$/');
});

it('is online only when its last heartbeat is less than 3 minutes old', function (?int $minutesAgo, bool $online) {
    $this->freezeSecond();
    $client = Client::factory()->create([
        'last_heartbeat_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
    ]);

    expect($client->isOnline())->toBe($online);
})->with([
    'never seen' => [null, false],
    'just now' => [0, true],
    '2 minutes ago' => [2, true],
    '3 minutes ago' => [3, false],
]);

it('keeps its token when disabled, so re-enabling restores access', function () {
    [$client, $token] = cabinetWithToken();

    $client->disable();
    expect($client->status)->toBe(ClientStatus::Disabled)
        ->and($client->tokens()->count())->toBe(1);

    $client->enable();
    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))->assertOk();
});

describe('ClientTokenIssuer', function () {
    it('keeps a single valid token per client', function () {
        [$client, $firstToken] = cabinetWithToken();

        $secondToken = app(ClientTokenIssuer::class)->issue($client);

        expect($client->tokens()->count())->toBe(1);
        $this->getJson('/api/v1/ping', cabinetHeaders($client, $firstToken))->assertUnauthorized();
        $this->getJson('/api/v1/ping', cabinetHeaders($client, $secondToken))->assertOk();
    });

    it('grants abilities according to the client type', function (ClientType $type, array $abilities) {
        $client = Client::factory()->create(['type' => $type]);

        app(ClientTokenIssuer::class)->issue($client);

        expect($client->tokens()->first()->abilities)->toBe($abilities);
    })->with([
        'maui' => [ClientType::Maui, ['session', 'scores:write', 'scores:read', 'repository:read']],
        'service' => [ClientType::Service, ['catalog:write', 'repository:read']],
    ]);
});
