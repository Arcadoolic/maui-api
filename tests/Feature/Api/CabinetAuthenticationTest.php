<?php

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Support\Facades\Log;

it('rejects a request without credentials', function () {
    $this->getJson('/api/v1/ping')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects an unknown token', function () {
    [$client] = cabinetWithToken();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, '999|not-a-real-token'))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects a valid token presented with another client key', function () {
    [, $token] = cabinetWithToken();
    $otherClient = Client::factory()->create();

    $this->getJson('/api/v1/ping', cabinetHeaders($otherClient, $token))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects a token without the key header', function () {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token);
    unset($headers['X-Maui-Key']);

    $this->getJson('/api/v1/ping', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects an expired token', function () {
    [$client, $token] = cabinetWithToken();
    $client->tokens()->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects a disabled client', function () {
    [$client, $token] = cabinetWithToken();
    $client->disable();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'client_disabled');
});

it('rejects a service account token on cabinet endpoints', function () {
    [$client, $token] = cabinetWithToken(['type' => ClientType::Service]);

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

it('rejects a missing or malformed machine fingerprint', function (?string $fingerprint) {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token);
    $headers['X-Maui-Machine'] = $fingerprint;

    $this->getJson('/api/v1/ping', array_filter($headers))
        ->assertBadRequest()
        ->assertJsonPath('code', 'machine_fingerprint_missing');

    expect($client->fresh()->machine_fingerprint_hash)->toBeNull();
})->with([
    'missing' => null,
    'too short' => str_repeat('a', 63),
    'uppercase' => strtoupper(hash('sha256', 'x')),
    'not hex' => str_repeat('z', 64),
]);

it('logs authentication failures without the token', function () {
    Log::spy();
    [$client] = cabinetWithToken();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, '999|super-secret-value'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'maui.auth_failed'
            && $context['key'] === $client->public_key
            && ! str_contains(json_encode($context), 'super-secret-value'))
        ->once();
});

it('logs rejected requests from authenticated clients', function (string $event, Closure $setUp) {
    Log::spy();
    [$client, $token] = cabinetWithToken($setUp());

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === $event
            && $context['key'] === $client->public_key
            && ! str_contains(json_encode($context), $token))
        ->once();
})->with([
    'disabled client' => ['maui.client_disabled', fn () => ['status' => ClientStatus::Disabled]],
    'service account' => ['maui.insufficient_ability', fn () => ['type' => ClientType::Service]],
]);

it('rate limits requests per client key', function () {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token);

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/ping', $headers)->assertOk();
    }

    $this->getJson('/api/v1/ping', $headers)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});
