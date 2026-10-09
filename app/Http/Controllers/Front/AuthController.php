<?php

namespace App\Http\Controllers\Front;

use App\Http\Middleware\AuthenticateMember;
use App\Http\Problems\ApiProblemException;
use App\Models\MemberInvitation;
use App\Services\Members\DiscordOAuth;
use App\Services\Members\DiscordUnavailable;
use App\Services\Members\MemberAccess;
use App\Services\Members\MemberAccessDenied;
use App\Support\Front;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Discord login of the front's members (docs/DECISIONS.md D64, D65). The two
 * login steps are pages the browser is sent to, not calls of the front: they
 * always end with a redirect to it, with an `error` when the login failed.
 */
final class AuthController
{
    private const STATE_KEY = 'front.discord.state';

    private const INVITATION_KEY = 'front.discord.invitation';

    public function __construct(private readonly DiscordOAuth $discord) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->discord->isConfigured()) {
            return $this->backToLogin('discord_unavailable');
        }

        $state = Str::random(40);
        $invitation = $request->query('invitation');
        $request->session()->put(self::STATE_KEY, $state);
        // Kept on this side while the visitor is on Discord: only needed for a new account.
        $request->session()->put(self::INVITATION_KEY, is_string($invitation) && $invitation !== '' ? $invitation : null);

        return redirect()->away($this->discord->authorizeUrl($state));
    }

    public function callback(Request $request, MemberAccess $access): RedirectResponse
    {
        $state = $request->session()->pull(self::STATE_KEY);
        $invitation = $request->session()->pull(self::INVITATION_KEY);
        $returned = $request->query('state');
        if (! is_string($state) || ! is_string($returned) || ! hash_equals($state, $returned)) {
            return $this->backToLogin('discord_failed');
        }
        if ($request->query('error') !== null) {
            return $this->backToLogin('discord_denied');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return $this->backToLogin('discord_failed');
        }

        try {
            $member = $access->enter($this->discord->user($code), is_string($invitation) ? $invitation : null);
        } catch (DiscordUnavailable $exception) {
            report($exception);

            return $this->backToLogin('discord_failed');
        } catch (MemberAccessDenied $denied) {
            return $this->backToLogin($denied->reason);
        }

        // Remembered: players come back for weeks without going through Discord again, long
        // after the session has ended.
        $guard = Auth::guard(AuthenticateMember::GUARD);
        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration(max(1, (int) config('front.remember_days')) * 24 * 60);
        }
        $guard->login($member, remember: true);
        $request->session()->regenerate();

        return redirect()->away(Front::url('/'));
    }

    /** Lets the invitation page of the front tell a dead link before the visitor goes to Discord. */
    public function invitation(string $token): JsonResponse
    {
        $invitation = MemberInvitation::query()->forToken($token)->first();
        if ($invitation === null || ! $invitation->isUsable()) {
            throw ApiProblemException::invitationUnavailable();
        }

        return new JsonResponse(['status' => 'valid']);
    }

    public function logout(Request $request): Response
    {
        Auth::guard(AuthenticateMember::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function backToLogin(string $error): RedirectResponse
    {
        return redirect()->away(Front::url('/login?error='.$error));
    }
}
