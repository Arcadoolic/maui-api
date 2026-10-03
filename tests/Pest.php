<?php

use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Models\Player;
use App\Services\ClientTokenIssuer;
use App\Services\Invitations\InvitationIssuer;
use App\Services\Invitations\IssuedInvitation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// TestCase uses RefreshDatabase and guards against non-testing databases.
pest()->extend(TestCase::class)
    ->in('Feature');

/**
 * A valid machine fingerprint (64 lowercase hex characters), as MAUI computes it.
 */
function fingerprint(string $machine = 'cabinet-a'): string
{
    return hash('sha256', $machine);
}

/**
 * Headers sent by a MAUI cabinet on every /api/v1 request.
 *
 * @return array<string, string>
 */
function cabinetHeaders(Client $client, string $token, ?string $fingerprint = null): array
{
    return [
        'X-Maui-Key' => $client->public_key,
        'Authorization' => 'Bearer '.$token,
        'X-Maui-Machine' => $fingerprint ?? fingerprint(),
    ];
}

/**
 * Creates an active MAUI client with a valid token.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Client, 1: string}
 */
function cabinetWithToken(array $attributes = []): array
{
    $client = Client::factory()->create($attributes);

    return [$client, app(ClientTokenIssuer::class)->issue($client)];
}

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

/**
 * Service accounts send no machine header.
 *
 * @return array<string, string>
 */
function serviceHeaders(Client $client, string $token): array
{
    return [
        'X-Maui-Key' => $client->public_key,
        'Authorization' => 'Bearer '.$token,
    ];
}

function issueInvitation(?Client $client = null, InvitationPurpose $purpose = InvitationPurpose::Initial): IssuedInvitation
{
    return app(InvitationIssuer::class)->issue($client ?? Client::factory()->create(), $purpose);
}

/** Extracts the MAUI1 configuration string displayed on the claimed page. */
function configurationStringFrom(TestResponse $response): string
{
    preg_match('/MAUI1\.[A-Za-z0-9_-]+/', $response->getContent(), $matches);

    return $matches[0] ?? '';
}

/**
 * Creates a player on a cabinet (its origin, D54), linked to it, with a known PIN.
 *
 * @param  array<string, mixed>  $attributes
 */
function linkedPlayer(Client $client, array $attributes = [], string $pin = '1234'): Player
{
    $player = Player::factory()->create([...$attributes, 'pin' => $pin]);
    $player->forceFill(['origin_client_id' => $client->id])->save();
    $player->clients()->attach($client, ['linked_at' => now()]);

    return $player;
}
