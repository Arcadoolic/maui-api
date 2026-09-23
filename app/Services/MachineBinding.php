<?php

namespace App\Services;

use App\Models\Client;

/**
 * "One key, one cabinet": binds a client to the first machine fingerprint it
 * presents, then only accepts that fingerprint (docs/DECISIONS.md D3, D13).
 */
final class MachineBinding
{
    private const FINGERPRINT_PATTERN = '/^[0-9a-f]{64}$/';

    public static function isValidFingerprint(mixed $fingerprint): bool
    {
        return is_string($fingerprint) && preg_match(self::FINGERPRINT_PATTERN, $fingerprint) === 1;
    }

    public function bind(Client $client, string $fingerprint): BindingResult
    {
        $hash = hash('sha256', $fingerprint);
        $newlyBound = false;

        if ($client->machine_fingerprint_hash === null) {
            // Conditional update: if another machine bound the client since we
            // read it, no row is affected and the comparison below rejects us.
            $newlyBound = Client::query()
                ->whereKey($client->getKey())
                ->whereNull('machine_fingerprint_hash')
                ->update(['machine_fingerprint_hash' => $hash, 'bound_at' => now()]) === 1;

            $client->refresh();
        }

        $matches = $client->machine_fingerprint_hash !== null
            && hash_equals($client->machine_fingerprint_hash, $hash);

        return new BindingResult($matches, $newlyBound);
    }
}
