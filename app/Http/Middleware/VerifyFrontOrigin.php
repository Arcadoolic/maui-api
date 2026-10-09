<?php

namespace App\Http\Middleware;

use App\Http\Problems\ApiProblemException;
use App\Support\Front;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection of the front's session (docs/DECISIONS.md D64): a request
 * that changes something must come from a page of the front. Browsers always
 * send `Origin` on such requests, and a page of another site cannot forge it.
 */
final class VerifyFrontOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $request->headers->get('Origin') !== Front::origin()) {
            throw ApiProblemException::originNotAllowed();
        }

        return $next($request);
    }
}
