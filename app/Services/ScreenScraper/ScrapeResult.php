<?php

namespace App\Services\ScreenScraper;

/** The outcome of asking ScreenScraper for one game. */
final readonly class ScrapeResult
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    /** The day's quota is spent, or too many threads: no use asking for more now. */
    public const QUOTA = 'quota';

    public const ERROR = 'error';

    public function __construct(
        public string $status,
        public ?ScrapedGame $game = null,
        public ?string $message = null,
    ) {}
}
