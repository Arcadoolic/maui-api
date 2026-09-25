<?php

use App\Models\Client;
use App\Services\MachineBinding;

it('binds an unbound client to the first fingerprint it sees', function () {
    [$client, $token] = cabinetWithToken();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-a')))
        ->assertOk()
        ->assertJsonPath('machine.newly_bound', true);

    $client->refresh();
    expect($client->machine_fingerprint_hash)->toBe(hash('sha256', fingerprint('cabinet-a')))
        ->and($client->bound_at)->not->toBeNull();
});

it('never stores the raw fingerprint', function () {
    [$client, $token] = cabinetWithToken();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-a')))->assertOk();

    expect($client->fresh()->machine_fingerprint_hash)->not->toBe(fingerprint('cabinet-a'));
});

it('accepts later requests from the bound machine', function () {
    [$client, $token] = cabinetWithToken();
    $headers = cabinetHeaders($client, $token, fingerprint('cabinet-a'));

    $this->getJson('/api/v1/ping', $headers)->assertOk();

    $this->getJson('/api/v1/ping', $headers)
        ->assertOk()
        ->assertJsonPath('machine.newly_bound', false);
});

it('rejects another machine once bound', function () {
    [$client, $token] = cabinetWithToken();
    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-a')))->assertOk();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-b')))
        ->assertConflict()
        ->assertJsonPath('code', 'machine_mismatch');

    expect($client->fresh()->machine_fingerprint_hash)->toBe(hash('sha256', fingerprint('cabinet-a')));
});

it('binds on any cabinet endpoint, not only ping', function () {
    [$client, $token] = cabinetWithToken();

    $this->postJson('/api/v1/heartbeat', [], cabinetHeaders($client, $token, fingerprint('cabinet-a')))
        ->assertNoContent();

    expect($client->fresh()->machine_fingerprint_hash)->toBe(hash('sha256', fingerprint('cabinet-a')));
});

it('lets only one machine win a concurrent first binding', function () {
    $client = Client::factory()->create();
    $staleCopy = Client::find($client->id);

    // Another request binds the client between our read and our write.
    app(MachineBinding::class)->bind($client, fingerprint('cabinet-a'));

    $result = app(MachineBinding::class)->bind($staleCopy, fingerprint('cabinet-b'));

    expect($result->matches)->toBeFalse()
        ->and($client->fresh()->machine_fingerprint_hash)->toBe(hash('sha256', fingerprint('cabinet-a')));
});

it('allows a new binding after the binding is reset', function () {
    [$client, $token] = cabinetWithToken();
    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-a')))->assertOk();

    $client->resetMachineBinding();

    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-b')))
        ->assertOk()
        ->assertJsonPath('machine.newly_bound', true);
});
