<?php

namespace App\Http\Middleware;

use App\Http\Problems\ApiProblemException;
use App\Models\Client;
use App\Services\BindingResult;
use App\Services\MachineBinding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a MAUI cabinet: key + token, active client, ability, then
 * machine binding. Usage: `cabinet:<ability>`.
 */
final class AuthenticateCabinet
{
    public const KEY_HEADER = 'X-Maui-Key';

    public const MACHINE_HEADER = 'X-Maui-Machine';

    private const CLIENT_ATTRIBUTE = 'maui.client';

    private const BINDING_ATTRIBUTE = 'maui.binding';

    public function __construct(private readonly MachineBinding $machineBinding) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $client = $this->authenticate($request);

        if (! $client->isActive()) {
            $this->logRejection($request, 'maui.client_disabled', $client);

            throw ApiProblemException::clientDisabled();
        }

        if (! $client->tokenCan($ability)) {
            $this->logRejection($request, 'maui.insufficient_ability', $client);

            throw ApiProblemException::insufficientAbility();
        }

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

        $request->attributes->set(self::CLIENT_ATTRIBUTE, $client);
        $request->attributes->set(self::BINDING_ATTRIBUTE, $binding);

        return $next($request);
    }

    public static function client(Request $request): Client
    {
        $client = $request->attributes->get(self::CLIENT_ATTRIBUTE);
        assert($client instanceof Client, 'Route is not behind the cabinet middleware.');

        return $client;
    }

    public static function binding(Request $request): BindingResult
    {
        $binding = $request->attributes->get(self::BINDING_ATTRIBUTE);
        assert($binding instanceof BindingResult, 'Route is not behind the cabinet middleware.');

        return $binding;
    }

    private function authenticate(Request $request): Client
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

    /**
     * Security trail for requests rejected after authentication: problem
     * exceptions are not reported (bootstrap/app.php), so log them here.
     */
    private function logRejection(Request $request, string $event, Client $client): void
    {
        Log::warning($event, ['key' => $client->public_key, 'ip' => $request->ip()]);
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
