<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Addresses (comma-separated, CIDR allowed) whose X-Forwarded-* headers
    | are trusted, read by Laravel's TrustProxies middleware. Unset locally:
    | FrankenPHP is reached directly. On staging, nginx on the host reaches
    | the container through the Docker bridge, so the bridge range is listed
    | there (docs/DECISIONS.md D40). Never "*" while the app port is public.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
