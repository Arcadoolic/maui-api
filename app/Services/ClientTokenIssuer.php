<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Issues the API token of a client. A client never has more than one valid token.
 */
final class ClientTokenIssuer
{
    public const TOKEN_NAME = 'api';

    /**
     * Revokes every existing token of the client and returns the new plain-text token.
     * The plain-text value is never stored: display it once, then forget it.
     */
    public function issue(Client $client): string
    {
        return DB::transaction(function () use ($client): string {
            // Row lock: two concurrent issuances must not both leave a token behind.
            Client::query()->whereKey($client->getKey())->lockForUpdate()->first();

            $client->tokens()->delete();

            return $client->createToken(self::TOKEN_NAME, $client->type->abilities())->plainTextToken;
        });
    }
}
