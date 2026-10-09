<?php

namespace App\Support;

/** The hiscores front, as this API knows it (config/front.php). */
final class Front
{
    /** A URL of the front, e.g. `Front::url('/login')`. */
    public static function url(string $path = ''): string
    {
        return rtrim((string) config('front.url'), '/').$path;
    }

    /** What a browser on the front sends as `Origin`: scheme, host and port. */
    public static function origin(): string
    {
        $parts = parse_url(self::url());
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$port;
    }
}
