<?php

use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Invitations\InvitationIssuer;

it('creates an invitation whose link carries a token stored only as a hash', function () {
    $this->freezeSecond();
    $client = Client::factory()->create();

    $issued = app(InvitationIssuer::class)->issue($client, InvitationPurpose::Initial);

    expect($issued->url)->toBe(url('/invite/'.$issued->plainToken))
        ->and($issued->plainToken)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($issued->invitation->token_hash)->toBe(hash('sha256', $issued->plainToken))
        ->and($issued->invitation->purpose)->toBe(InvitationPurpose::Initial)
        ->and($issued->invitation->expires_at->equalTo(now()->addHours(72)))->toBeTrue()
        ->and(Invitation::query()->where('token_hash', $issued->plainToken)->exists())->toBeFalse();
});

it('does not issue an API token when the invitation is created', function () {
    $client = Client::factory()->create();

    app(InvitationIssuer::class)->issue($client, InvitationPurpose::Initial);

    expect($client->tokens()->count())->toBe(0);
});

it('records the admin who created the invitation', function () {
    $admin = User::factory()->create();

    $issued = app(InvitationIssuer::class)->issue(Client::factory()->create(), InvitationPurpose::Initial, $admin);

    expect($issued->invitation->created_by)->toBe($admin->id);
});

it('invalidates the previous pending invitation of the client', function () {
    $client = Client::factory()->create();
    $first = app(InvitationIssuer::class)->issue($client, InvitationPurpose::Initial);

    app(InvitationIssuer::class)->issue($client, InvitationPurpose::Initial);

    expect($first->invitation->fresh()->isClaimable())->toBeFalse();
});

it('refuses invitations for service accounts', function () {
    app(InvitationIssuer::class)->issue(Client::factory()->service()->create(), InvitationPurpose::Initial);
})->throws(InvalidArgumentException::class);
