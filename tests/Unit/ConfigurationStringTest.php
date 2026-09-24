<?php

use App\Support\ConfigurationString;

it('encodes credentials as MAUI1 followed by unpadded base64url JSON', function () {
    $encoded = ConfigurationString::encode('https://api.example.org', 'mk_7F3aQ9dLx2PzK8wR4mT6vYb1', '12|secret+/token');

    expect($encoded)->toStartWith('MAUI1.')
        ->and(substr($encoded, 6))->toMatch('/^[A-Za-z0-9_-]+$/');

    $json = json_decode(base64_decode(strtr(substr($encoded, 6), '-_', '+/')), true);
    expect($json)->toBe([
        'url' => 'https://api.example.org',
        'key' => 'mk_7F3aQ9dLx2PzK8wR4mT6vYb1',
        'token' => '12|secret+/token',
    ]);
});

it('decodes what it encodes', function () {
    $encoded = ConfigurationString::encode('https://api.example.org', 'mk_abc', '1|t');

    expect(ConfigurationString::decode($encoded))->toBe([
        'url' => 'https://api.example.org',
        'key' => 'mk_abc',
        'token' => '1|t',
    ]);
});

it('strips a trailing slash from the API URL', function () {
    $decoded = ConfigurationString::decode(ConfigurationString::encode('https://api.example.org/', 'mk_abc', '1|t'));

    expect($decoded['url'])->toBe('https://api.example.org');
});

it('rejects a string with another format version', function () {
    ConfigurationString::decode('MAUI2.eyJ1cmwiOiJ4In0');
})->throws(InvalidArgumentException::class);
