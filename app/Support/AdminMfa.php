<?php

namespace App\Support;

/**
 * Multi-factor authentication of the back office (docs/DECISIONS.md D35,
 * D55): required everywhere, except where an operator turned it off outside
 * production, and labelled after the server so that an authenticator app
 * holding the codes of several servers tells them apart.
 */
final class AdminMfa
{
    /**
     * Off only when `MAUI_ADMIN_MFA=false` and the environment is not
     * production: a setting copied to a real server changes nothing there.
     */
    public static function isEnabled(): bool
    {
        return app()->environment('production') || (bool) config('maui.admin_mfa.enabled', true);
    }

    /**
     * Name of the account in the authenticator app: `MAUI_ADMIN_MFA_LABEL`,
     * else the application name and the host of `APP_URL`, e.g.
     * "MAUI-API (api.maui.staging.afronob.com)".
     */
    public static function label(): string
    {
        $label = config('maui.admin_mfa.label');
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $name = (string) config('app.name');
        $url = parse_url((string) config('app.url'));
        $host = is_array($url) ? ($url['host'] ?? null) : null;
        if ($host === null) {
            return $name;
        }
        $port = isset($url['port']) ? ':'.$url['port'] : '';

        return "{$name} ({$host}{$port})";
    }
}
