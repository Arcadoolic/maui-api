<?php

namespace App\Services;

use App\Http\Problems\ApiProblemException;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authenticates a client from its key + token, then checks that it is active
 * and holds the ability. Machine binding is left to the caller.
 */
final class ClientAuthenticator
{
    public const KEY_HEADER = 'X-Maui-Key';

    public const MACHINE_HEADER = 'X-Maui-Machine';

    public function __construct(private readonly MachineBinding $machineBinding) {}

    public function authenticate(Request $request, string $ability): Client
    {
        $client = $this->identify($request);

        if (! $client->isActive()) {
            $this->logRejection($request, 'maui.client_disabled', $client);

            throw ApiProblemException::clientDisabled();
        }

        if (! $client->tokenCan($ability)) {
            $this->logRejection($request, 'maui.insufficient_ability', $client);

            throw ApiProblemException::insufficientAbility();
        }

        return $client;
    }

    /**
     * Requires the machine header and binds the client to it on first use
     * (docs/DECISIONS.md D3, D13).
     */
    public function bindMachine(Request $request, Client $client): BindingResult
    {
        $fingerprint = $request->header(self::MACHINE_HEADER);
        if (! MachineBinding::isValidFingerprint($fingerprint)) {
            throw ApiProblemException::machineFingerprintMissing();
        }

        /** @var string $fingerprint */
        $binding = $this->machineBinding->bind($client, $fingerprint);
        if (! $binding->matches) {
            $this->logRejection($request, 'maui.machine_mismatch', $client);

            throw ApiProblemException::machineMismatch();
        }

        return $binding;
    }

    /**
     * Security trail for requests rejected after authentication: problem
     * exceptions are not reported (bootstrap/app.php), so log them here.
     */
    public function logRejection(Request $request, string $event, Client $client): void
    {
        Log::warning($event, ['key' => $client->public_key, 'ip' => $request->ip()]);
    }

    private function identify(Request $request): Client
    {
        $key = $request->header(self::KEY_HEADER);
        $plainToken = $request->bearerToken();

        if (! is_string($key) || $plainToken === null) {
            $this->rejectAuthentication($request, 'missing_credentials');
        }

        $accessToken = PersonalAccessToken::findToken($plainToken);
        $client = $accessToken?->tokenable;

        if (! $accessToken instanceof PersonalAccessToken || ! $client instanceof Client) {
            $this->rejectAuthentication($request, 'unknown_token');
        }

        if (! hash_equals($client->public_key, $key)) {
            $this->rejectAuthentication($request, 'key_mismatch');
        }

        if ($this->isExpired($accessToken)) {
            $this->rejectAuthentication($request, 'expired_token');
        }

        return $client->withAccessToken($accessToken);
    }

    private function rejectAuthentication(Request $request, string $reason): never
    {
        $key = $request->header(self::KEY_HEADER);

        // The key is public; the token is never logged.
        Log::warning('maui.auth_failed', [
            'reason' => $reason,
            'key' => is_string($key) ? Str::limit($key, 64) : null,
            'ip' => $request->ip(),
        ]);

        throw ApiProblemException::unauthenticated();
    }

    private function isExpired(PersonalAccessToken $token): bool
    {
        if ($token->expires_at?->isPast()) {
            return true;
        }

        $lifetime = config('sanctum.expiration');

        return is_numeric($lifetime)
            && $token->created_at?->lte(now()->subMinutes((int) $lifetime));
    }
}
