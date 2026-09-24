<?php

use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Services\ClientTokenIssuer;
use App\Services\Invitations\InvitationIssuer;
use App\Services\Invitations\IssuedInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
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
