<?php

namespace App\Http\Middleware;

use App\Http\Problems\ApiProblemException;
use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A logged-in, active member of the front (docs/DECISIONS.md D64). */
final class AuthenticateMember
{
    public const GUARD = 'member';

    public function handle(Request $request, Closure $next): Response
    {
        $member = Auth::guard(self::GUARD)->user();
        if (! $member instanceof Member) {
            throw ApiProblemException::unauthenticated();
        }
        // Disabled since it logged in: the session ends here.
        if (! $member->isActive()) {
            Auth::guard(self::GUARD)->logout();

            throw ApiProblemException::memberDisabled();
        }

        return $next($request);
    }

    public static function member(Request $request): Member
    {
        $member = Auth::guard(self::GUARD)->user();

        return $member instanceof Member ? $member : throw ApiProblemException::unauthenticated();
    }
}
