<?php

use App\Services\ScreenScraper\ScreenScraperClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run by the `scheduler` container (docs/DECISIONS.md D72), times in UTC.

// Game pages: a few more games completed with ScreenScraper every night, the
// answers of the month before asked again (D68). Skipped without credentials.
Schedule::command('catalog:scrape')
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->when(fn (): bool => app(ScreenScraperClient::class)->isConfigured());
