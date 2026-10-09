<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front's session has a cookie of its own (docs/DECISIONS.md D64). The
 * back office uses the default one: where both are served by one host
 * (`localhost` in development, cookies ignore the port), a member logging
 * out would otherwise end the admin's session, and the reverse. Must run
 * before StartSession.
 */
final class UseFrontSession
{
    public const COOKIE = 'maui-front-session';

    public function handle(Request $request, Closure $next): Response
    {
        config(['session.cookie' => self::COOKIE]);
        // The session store may already be built, with the default name.
        app(SessionManager::class)->driver()->setName(self::COOKIE);

        return $next($request);
    }
}
