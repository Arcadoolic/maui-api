<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\BindingResult;
use App\Services\ClientAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a MAUI cabinet: key + token, active client, ability, then
 * machine binding. Usage: `cabinet:<ability>`.
 */
final class AuthenticateCabinet
{
    public const KEY_HEADER = ClientAuthenticator::KEY_HEADER;

    public const MACHINE_HEADER = ClientAuthenticator::MACHINE_HEADER;

    private const CLIENT_ATTRIBUTE = 'maui.client';

    private const BINDING_ATTRIBUTE = 'maui.binding';

    public function __construct(private readonly ClientAuthenticator $authenticator) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $client = $this->authenticator->authenticate($request, $ability);
        $binding = $this->authenticator->bindMachine($request, $client);

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
}
