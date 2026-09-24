<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;

/**
 * The single string a cabinet owner pastes into the MAUI BO:
 * `MAUI1.` + unpadded base64url(JSON {url, key, token}) (docs/DECISIONS.md D14).
 */
final class ConfigurationString
{
    public const PREFIX = 'MAUI1.';

    public static function encode(string $apiUrl, string $key, string $token): string
    {
        $json = json_encode(
            ['url' => rtrim($apiUrl, '/'), 'key' => $key, 'token' => $token],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return self::PREFIX.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{url: string, key: string, token: string}
     */
    public static function decode(string $encoded): array
    {
        if (! str_starts_with($encoded, self::PREFIX)) {
            throw new InvalidArgumentException('Unsupported configuration string format.');
        }

        $json = base64_decode(strtr(substr($encoded, strlen(self::PREFIX)), '-_', '+/'), strict: true);

        try {
            $data = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Malformed configuration string.', previous: $exception);
        }

        if (! is_array($data) || ! is_string($data['url'] ?? null) || ! is_string($data['key'] ?? null) || ! is_string($data['token'] ?? null)) {
            throw new InvalidArgumentException('Incomplete configuration string.');
        }

        // Unknown fields are ignored on purpose (D14): only a breaking change bumps the prefix.
        return ['url' => $data['url'], 'key' => $data['key'], 'token' => $data['token']];
    }
}
