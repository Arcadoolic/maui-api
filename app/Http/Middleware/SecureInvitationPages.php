<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invitation pages carry a secret in their URL and display credentials:
 * never cache, index or leak them, and allow nothing but their own nonce'd
 * style and script.
 */
final class SecureInvitationPages
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = self::withPrivacyHeaders($next($request));

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'none'",
            "style-src 'nonce-{$nonce}'",
            "script-src 'nonce-{$nonce}'",
            "form-action 'self'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
        ]));

        return $response;
    }

    /**
     * Headers that keep the secret URL out of caches, referrers and search
     * engines. Also applied to error responses (419, 429, 5xx) rendered from
     * exceptions, which may never reach this middleware (bootstrap/app.php).
     */
    public static function withPrivacyHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public static function appliesTo(Request $request): bool
    {
        return $request->is('invite/*');
    }
}
