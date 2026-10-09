<?php

namespace App\Services\Members;

use App\Support\Front;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Discord login of the front's members: the OAuth2 authorization code flow,
 * scope `identify` only (docs/DECISIONS.md D64). Two calls, so no package.
 */
final class DiscordOAuth
{
    public const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';

    public const TOKEN_URL = 'https://discord.com/api/oauth2/token';

    public const USER_URL = 'https://discord.com/api/users/@me';

    private const TIMEOUT_SECONDS = 10;

    public function isConfigured(): bool
    {
        return filled(config('front.discord.client_id')) && filled(config('front.discord.client_secret'));
    }

    /** Through the front, whose server passes it on: the session cookie is the front's. */
    public function redirectUri(): string
    {
        return Front::url('/api/v1/front/auth/discord/callback');
    }

    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('front.discord.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'identify',
            'state' => $state,
            // No consent screen again for an account that already agreed.
            'prompt' => 'none',
        ]);
    }

    /** The account behind an authorization code. The access token is not kept. */
    public function user(string $code): DiscordUser
    {
        try {
            $token = Http::asForm()->timeout(self::TIMEOUT_SECONDS)->post(self::TOKEN_URL, [
                'client_id' => config('front.discord.client_id'),
                'client_secret' => config('front.discord.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);
            $accessToken = $token->json('access_token');
            if (! $token->successful() || ! is_string($accessToken)) {
                // Discord's own reason, e.g. `invalid_client` for a wrong client secret.
                $reason = $token->json('error');

                throw new DiscordUnavailable('Discord refused the authorization code: HTTP '.$token->status().(is_string($reason) ? ' '.$reason : ''));
            }

            $user = Http::withToken($accessToken)->timeout(self::TIMEOUT_SECONDS)->get(self::USER_URL);
        } catch (ConnectionException $exception) {
            throw new DiscordUnavailable('Discord did not answer.', previous: $exception);
        }

        $id = $user->json('id');
        $username = $user->json('username');
        if (! $user->successful() || ! is_string($id) || ! is_string($username)) {
            throw new DiscordUnavailable('Discord did not return the account: HTTP '.$user->status());
        }

        $displayName = $user->json('global_name');
        $avatar = $user->json('avatar');

        return new DiscordUser(
            $id,
            $username,
            is_string($displayName) && $displayName !== '' ? $displayName : null,
            is_string($avatar) && $avatar !== '' ? $avatar : null,
        );
    }
}
