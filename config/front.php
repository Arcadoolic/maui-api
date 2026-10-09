<?php

return [

    /*
    | Public URL of the hiscores front (maui-hifront). Its server passes
    | /api/v1/front/* on to this API, so the browser only ever talks to this
    | URL: the session cookie belongs to it, invitation links point to it and
    | Discord sends the login back to it (docs/DECISIONS.md D64).
    */
    'url' => env('FRONT_URL', 'http://localhost:5180'),

    /*
    | Days a member stays logged in on a browser without going through Discord
    | again (the "remember me" cookie). The session itself ends after
    | SESSION_LIFETIME minutes without a request, as the back office's does.
    */
    'remember_days' => (int) env('FRONT_REMEMBER_DAYS', 60),

    /*
    | The Discord application members log in with (OAuth2, scope `identify`).
    | Its redirect URI is <url>/api/v1/front/auth/discord/callback. Unset:
    | nobody can log in to the front.
    */
    'discord' => [
        'client_id' => env('FRONT_DISCORD_CLIENT_ID'),
        'client_secret' => env('FRONT_DISCORD_CLIENT_SECRET'),
    ],

];
