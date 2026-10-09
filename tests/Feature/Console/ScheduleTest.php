<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// What the scheduler container runs (docs/DECISIONS.md D72).

function scheduled(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command))
        ?? throw new RuntimeException("{$command} is not scheduled.");
}

it('completes the game pages every night, when ScreenScraper is configured', function () {
    $event = scheduled('catalog:scrape');
    expect($event->expression)->toBe('15 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    config(['screenscraper.dev_id' => 'dev', 'screenscraper.dev_password' => 's', 'screenscraper.user' => 'u', 'screenscraper.password' => 'p']);
    expect($event->filtersPass(app()))->toBeTrue();

    config(['screenscraper.dev_id' => null]);
    expect($event->filtersPass(app()))->toBeFalse();
});
