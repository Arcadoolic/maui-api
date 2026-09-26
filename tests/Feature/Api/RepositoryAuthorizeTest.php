<?php

use App\Models\Client;
use App\Services\ClientTokenIssuer;
use Illuminate\Support\Facades\DB;

// forward_auth target of the starting-pack repository (docs/DECISIONS.md D46).

/**
 * Creates an active service account with a valid token.
 *
 * @return array{0: Client, 1: string}
 */
function serviceWithToken(): array
{
    $client = Client::factory()->service()->create();

    return [$client, app(ClientTokenIssuer::class)->issue($client)];
}

/** Service accounts send no machine header. */
function serviceHeaders(Client $client, string $token): array
{
    return [
        'X-Maui-Key' => $client->public_key,
        'Authorization' => 'Bearer '.$token,
    ];
}

it('authorizes a cabinet and binds it on first use', function () {
    [$client, $token] = cabinetWithToken();

    $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token))
        ->assertNoContent();

    expect($client->fresh()->machine_fingerprint_hash)->toBe(hash('sha256', fingerprint()));
});

it('authorizes a service account without machine header', function () {
    [$client, $token] = serviceWithToken();

    $this->getJson('/api/v1/repository/authorize', serviceHeaders($client, $token))
        ->assertNoContent();

    expect($client->fresh()->machine_fingerprint_hash)->toBeNull();
});

it('rejects invalid credentials', function (string $case) {
    [$client, $token] = cabinetWithToken();
    $headers = match ($case) {
        'no credentials' => [],
        'unknown token' => cabinetHeaders($client, '999|not-a-real-token'),
        'key of another client' => cabinetHeaders(Client::factory()->create(), $token),
    };

    $this->getJson('/api/v1/repository/authorize', $headers)
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
})->with(['no credentials', 'unknown token', 'key of another client']);

it('rejects an expired token', function () {
    [$client, $token] = cabinetWithToken();
    $client->tokens()->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects a disabled client', function () {
    [$client, $token] = cabinetWithToken();
    $client->disable();

    $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'client_disabled');
});

it('rejects a token without the repository:read ability', function () {
    [$client, $token] = cabinetWithToken();
    $client->tokens()->update(['abilities' => json_encode(['session'])]);

    $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

it('requires a machine fingerprint from a cabinet', function () {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token);
    unset($headers['X-Maui-Machine']);

    $this->getJson('/api/v1/repository/authorize', $headers)
        ->assertBadRequest()
        ->assertJsonPath('code', 'machine_fingerprint_missing');
});

it('rejects a cabinet bound to another machine', function () {
    [$client, $token] = cabinetWithToken();
    $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token))->assertNoContent();

    $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token, fingerprint('cabinet-b')))
        ->assertConflict()
        ->assertJsonPath('code', 'machine_mismatch');
});

it('does not consume the cabinet rate limit', function () {
    [$client, $token] = cabinetWithToken();

    // Well beyond the 60/min of the cabinet limiter: a pack import sends many Range requests.
    for ($i = 0; $i < 70; $i++) {
        $this->getJson('/api/v1/repository/authorize', cabinetHeaders($client, $token))->assertNoContent();
    }

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))->assertOk();
});

it('grants repository:read to the tokens issued before the ability existed', function () {
    [$cabinet] = cabinetWithToken();
    [$service] = serviceWithToken();
    DB::table('personal_access_tokens')->where('tokenable_id', $cabinet->id)
        ->update(['abilities' => json_encode(['session', 'scores:write', 'scores:read'])]);
    DB::table('personal_access_tokens')->where('tokenable_id', $service->id)
        ->update(['abilities' => json_encode(['catalog:write'])]);

    $migration = require database_path('migrations/2026_09_26_120000_grant_repository_read_to_client_tokens.php');
    $migration->up();
    $migration->up(); // idempotent

    expect($cabinet->tokens()->first()->abilities)->toBe(['session', 'scores:write', 'scores:read', 'repository:read'])
        ->and($service->tokens()->first()->abilities)->toBe(['catalog:write', 'repository:read']);

    $migration->down();

    expect($cabinet->tokens()->first()->abilities)->toBe(['session', 'scores:write', 'scores:read']);
});
