<?php

// Repository discovery: the URL comes from the API, not from the cabinet (docs/DECISIONS.md D46).

it('returns the configured repository URL without trailing slash', function () {
    config(['maui.repository_url' => 'https://repo.example.org/']);
    [$client, $token] = cabinetWithToken();

    $this->getJson('/api/v1/repository', cabinetHeaders($client, $token))
        ->assertOk()
        ->assertExactJson(['url' => 'https://repo.example.org']);
});

it('returns a null URL when this server has no repository', function (?string $url) {
    config(['maui.repository_url' => $url]);
    [$client, $token] = cabinetWithToken();

    $this->getJson('/api/v1/repository', cabinetHeaders($client, $token))
        ->assertOk()
        ->assertExactJson(['url' => null]);
})->with(['unset' => null, 'empty' => '']);

it('hides the URL from a token without the repository:read ability', function () {
    config(['maui.repository_url' => 'https://repo.example.org']);
    [$client, $token] = cabinetWithToken();
    $client->tokens()->update(['abilities' => json_encode(['session'])]);

    $this->getJson('/api/v1/repository', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/repository')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});
