<?php

use App\Http\Middleware\UseFrontSession;
use App\Models\Member;
use App\Models\MemberInvitation;
use App\Services\Members\DiscordOAuth;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

// Members of the hiscores front: Discord login, on invitation (docs/DECISIONS.md D64, D65).

beforeEach(function () {
    config([
        'front.url' => 'https://hiscores.test',
        'front.discord.client_id' => '1234567890',
        'front.discord.client_secret' => 'discord-secret',
    ]);
});

/** Starts the login and returns the `state` sent to Discord. */
function startDiscordLogin(?string $invitation = null): string
{
    $response = test()->get('/api/v1/front/auth/discord'.($invitation === null ? '' : '?invitation='.$invitation))
        ->assertRedirect();
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

describe('redirect to Discord', function () {
    it('sends the browser to Discord with a state and the callback of the front', function () {
        $response = $this->get('/api/v1/front/auth/discord')->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        expect($location)->toStartWith(DiscordOAuth::AUTHORIZE_URL.'?')
            ->and($query['client_id'])->toBe('1234567890')
            ->and($query['response_type'])->toBe('code')
            ->and($query['scope'])->toBe('identify')
            ->and($query['redirect_uri'])->toBe('https://hiscores.test/api/v1/front/auth/discord/callback')
            ->and($query['state'])->toMatch('/^[A-Za-z0-9]{40}$/');
    });

    it('keeps its session in a cookie of its own, apart from the back office', function () {
        $backOfficeCookie = (string) config('session.cookie');

        $this->get('/api/v1/front/auth/discord')
            ->assertCookie(UseFrontSession::COOKIE)
            ->assertCookieMissing($backOfficeCookie);
    });

    it('names its cookie before the session is read', function () {
        $route = app('router')->getRoutes()->match(Request::create('/api/v1/front/me'));
        $middleware = app('router')->gatherRouteMiddleware($route);

        expect(array_search(UseFrontSession::class, $middleware, true))
            ->toBeLessThan(array_search(StartSession::class, $middleware, true));
    });

    it('goes back to the front when Discord is not configured', function () {
        config(['front.discord.client_id' => null]);

        $this->get('/api/v1/front/auth/discord')
            ->assertRedirect('https://hiscores.test/login?error=discord_unavailable');
    });
});

describe('callback', function () {
    it('creates the member of a valid invitation and logs it in', function () {
        fakeDiscord(['id' => '42', 'username' => 'blinky', 'global_name' => 'Blinky', 'avatar' => 'abc']);
        $invitation = issueMemberInvitation();
        $state = startDiscordLogin($invitation->plainToken);

        $this->get("/api/v1/front/auth/discord/callback?code=the-code&state={$state}")
            ->assertRedirect('https://hiscores.test/');

        $member = Member::query()->where('discord_id', '42')->firstOrFail();
        expect($member->username)->toBe('blinky')
            ->and($member->display_name)->toBe('Blinky')
            ->and($member->discord_avatar)->toBe('abc')
            ->and($member->member_invitation_id)->toBe($invitation->invitation->id)
            ->and($member->last_login_at)->not->toBeNull()
            ->and($invitation->invitation->refresh()->uses)->toBe(1)
            ->and(Auth::guard('member')->id())->toBe($member->id);

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === DiscordOAuth::TOKEN_URL
            && $request['code'] === 'the-code'
            && $request['client_secret'] === 'discord-secret'
            && $request['redirect_uri'] === 'https://hiscores.test/api/v1/front/auth/discord/callback');
    });

    it('remembers the member once its session has ended', function () {
        fakeDiscord(['id' => '42', 'username' => 'blinky']);
        $state = startDiscordLogin(issueMemberInvitation()->plainToken);
        $response = $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}");
        $recaller = Auth::guard('member')->getRecallerName();
        $cookie = $response->getCookie($recaller, decrypt: true, unserialize: false);
        expect($cookie)->not->toBeNull()
            // About 60 days, not Laravel's 400.
            ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(59)->getTimestamp())
            ->and($cookie->getExpiresTime())->toBeLessThan(now()->addDays(61)->getTimestamp());

        // Two hours later: the session is gone, the browser only has the remember cookie.
        $this->flushSession();
        Auth::forgetGuards();
        $this->getJson('/api/v1/front/me')->assertUnauthorized();
        Auth::forgetGuards();

        // getJson() sends no cookie unless told to.
        $this->withCredentials()->withCookie($recaller, $cookie->getValue())->getJson('/api/v1/front/me')
            ->assertOk()
            ->assertJsonPath('member.username', 'blinky');
    });

    it('forgets the browser of a member who logs out', function () {
        $member = Member::factory()->create();
        Auth::guard('member')->login($member, remember: true);
        $token = $member->getRememberToken();

        $this->postJson('/api/v1/front/logout', [], ['Origin' => 'https://hiscores.test'])->assertNoContent();

        // The token of the remember cookie is renewed: a copy of the old cookie is worth nothing.
        expect($member->refresh()->getRememberToken())->not->toBe($token);
    });

    it('logs a known member in without an invitation, and refreshes its Discord profile', function () {
        $member = Member::factory()->create(['discord_id' => '42', 'username' => 'old']);
        fakeDiscord(['id' => '42', 'username' => 'new', 'global_name' => null, 'avatar' => null]);
        $state = startDiscordLogin();

        $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}")
            ->assertRedirect('https://hiscores.test/');

        expect($member->refresh()->username)->toBe('new')
            ->and(Member::query()->count())->toBe(1)
            ->and(Auth::guard('member')->id())->toBe($member->id);
    });

    it('refuses an unknown Discord account without an invitation', function () {
        fakeDiscord(['id' => '42', 'username' => 'blinky']);
        $state = startDiscordLogin();

        $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}")
            ->assertRedirect('https://hiscores.test/login?error=invitation_required');

        expect(Member::query()->count())->toBe(0)
            ->and(Auth::guard('member')->check())->toBeFalse();
    });

    it('refuses an invitation that is unknown, expired, revoked or used up', function (Closure $token) {
        fakeDiscord(['id' => '42', 'username' => 'blinky']);
        $state = startDiscordLogin($token());

        $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}")
            ->assertRedirect('https://hiscores.test/login?error=invitation_unavailable');

        expect(Member::query()->count())->toBe(0);
    })->with([
        'unknown' => [fn () => str_repeat('x', 48)],
        'expired' => [fn () => issueMemberInvitation(['expires_at' => now()->subMinute()])->plainToken],
        'revoked' => [function () {
            $issued = issueMemberInvitation();
            $issued->invitation->forceFill(['revoked_at' => now()])->save();

            return $issued->plainToken;
        }],
        'used up' => [function () {
            $issued = issueMemberInvitation(['max_uses' => 1]);
            $issued->invitation->forceFill(['uses' => 1])->save();

            return $issued->plainToken;
        }],
    ]);

    it('takes several members on an invitation without a limit', function () {
        $invitation = issueMemberInvitation(['max_uses' => null]);
        // Http::fake() keeps its first answers: one call, three accounts in a row.
        Http::fake([
            DiscordOAuth::TOKEN_URL => Http::response(['access_token' => 'discord-access-token']),
            DiscordOAuth::USER_URL => Http::sequence()
                ->push(['id' => '1', 'username' => 'blinky'])
                ->push(['id' => '2', 'username' => 'pinky'])
                ->push(['id' => '3', 'username' => 'inky']),
        ]);

        foreach (range(1, 3) as $ignored) {
            $state = startDiscordLogin($invitation->plainToken);
            $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}")
                ->assertRedirect('https://hiscores.test/');
        }

        expect(Member::query()->count())->toBe(3)
            ->and($invitation->invitation->refresh()->uses)->toBe(3);
    });

    it('refuses a disabled member', function () {
        Member::factory()->disabled()->create(['discord_id' => '42']);
        fakeDiscord(['id' => '42', 'username' => 'blinky']);
        $state = startDiscordLogin();

        $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}")
            ->assertRedirect('https://hiscores.test/login?error=member_disabled');

        expect(Auth::guard('member')->check())->toBeFalse();
    });

    it('refuses a state it did not issue', function () {
        fakeDiscord(['id' => '42', 'username' => 'blinky']);
        startDiscordLogin(issueMemberInvitation()->plainToken);

        $this->get('/api/v1/front/auth/discord/callback?code=c&state=forged')
            ->assertRedirect('https://hiscores.test/login?error=discord_failed');

        Http::assertNothingSent();
        expect(Member::query()->count())->toBe(0);
    });

    it('tells a login cancelled on Discord apart', function () {
        $state = startDiscordLogin();

        $this->get("/api/v1/front/auth/discord/callback?error=access_denied&state={$state}")
            ->assertRedirect('https://hiscores.test/login?error=discord_denied');
    });

    it('goes back to the front when Discord fails', function () {
        Http::fake([DiscordOAuth::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $state = startDiscordLogin(issueMemberInvitation()->plainToken);

        $this->get("/api/v1/front/auth/discord/callback?code=c&state={$state}")
            ->assertRedirect('https://hiscores.test/login?error=discord_failed');

        expect(Member::query()->count())->toBe(0);
    });
});

describe('invitation check', function () {
    it('tells the front an invitation can be used', function () {
        $this->getJson('/api/v1/front/invitations/'.issueMemberInvitation()->plainToken)
            ->assertOk()
            ->assertExactJson(['status' => 'valid']);
    });

    it('answers 404 for an invitation that cannot be used', function () {
        $expired = issueMemberInvitation(['expires_at' => now()->subMinute()]);

        foreach ([str_repeat('x', 48), $expired->plainToken] as $token) {
            $this->getJson('/api/v1/front/invitations/'.$token)
                ->assertNotFound()
                ->assertJsonPath('code', 'invitation_unavailable');
        }
    });

    it('stores only the hash of the token', function () {
        $issued = issueMemberInvitation();

        expect($issued->invitation->token_hash)->toBe(MemberInvitation::hashToken($issued->plainToken))
            ->and($issued->url)->toBe('https://hiscores.test/invite/'.$issued->plainToken);
    });
});
