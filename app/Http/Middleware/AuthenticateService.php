<?php

namespace App\Http\Middleware;

use App\Enums\ClientType;
use App\Http\Problems\ApiProblemException;
use App\Services\ClientAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a service account: key + token, active client, ability. No
 * machine binding. Records `last_used_at`, its only "last seen"
 * (docs/DECISIONS.md D43). Usage: `service:<ability>`.
 */
final class AuthenticateService
{
    public function __construct(private readonly ClientAuthenticator $authenticator) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $client = $this->authenticator->authenticate($request, $ability);

        // Abilities come from the client type, but a token is a row anyone
        // with database access could edit: check the type as well.
        if ($client->type !== ClientType::Service) {
            $this->authenticator->logRejection($request, 'maui.insufficient_ability', $client);

            throw ApiProblemException::insufficientAbility();
        }

        $client->currentAccessToken()->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
