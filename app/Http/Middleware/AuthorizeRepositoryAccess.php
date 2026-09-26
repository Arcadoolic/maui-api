<?php

namespace App\Http\Middleware;

use App\Enums\ClientType;
use App\Services\ClientAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Access to the starting-pack repository (docs/DECISIONS.md D46): a cabinet
 * with the `repository:read` ability, bound to its machine like on the other
 * routes, or a service account, which sends no machine header.
 */
final class AuthorizeRepositoryAccess
{
    public const ABILITY = 'repository:read';

    public function __construct(private readonly ClientAuthenticator $authenticator) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $client = $this->authenticator->authenticate($request, self::ABILITY);

        if ($client->type === ClientType::Maui) {
            $this->authenticator->bindMachine($request, $client);
        }

        return $next($request);
    }
}
