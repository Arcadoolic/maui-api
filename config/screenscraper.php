<?php

return [

    /*
    | ScreenScraper account the game pages of the hiscores front are completed
    | with (docs/DECISIONS.md D68): a developer account, which ScreenScraper
    | gives on request, and a user account, whose quota the calls count
    | against. Unset: `catalog:scrape` refuses to run.
    */
    'dev_id' => env('SCREENSCRAPER_DEV_ID'),
    'dev_password' => env('SCREENSCRAPER_DEV_PASSWORD'),
    'soft_name' => env('SCREENSCRAPER_SOFT_NAME', 'maui-api'),
    'user' => env('SCREENSCRAPER_USER'),
    'password' => env('SCREENSCRAPER_PASSWORD'),

    /*
    | Milliseconds between two calls, media downloads included: ScreenScraper
    | allows few threads and asks callers to go easy.
    */
    'throttle_ms' => (int) env('SCREENSCRAPER_THROTTLE_MS', 1500),

    /*
    | A game is asked again after this many days: its page may have been
    | completed, or created, on ScreenScraper since.
    */
    'refresh_after_days' => 30,

    /* Bytes: a bigger media is not kept. */
    'max_media_bytes' => 4 * 1024 * 1024,

];
