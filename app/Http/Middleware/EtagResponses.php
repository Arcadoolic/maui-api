<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ETag on successful GET responses (docs/DECISIONS.md D52): a cabinet that
 * asks again for an unchanged leaderboard gets `304 Not Modified`, no body.
 * Private: the answers depend on the cabinet's token.
 */
final class EtagResponses
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
