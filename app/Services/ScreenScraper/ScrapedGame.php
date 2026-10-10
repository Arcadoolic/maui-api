<?php

namespace App\Services\ScreenScraper;

/** A game as ScreenScraper describes it, reduced to what the game pages show. */
final readonly class ScrapedGame
{
    /**
     * @param  list<string>  $genres
     * @param  array<string, string>  $mediaUrls  By our media type (GameMedia::TYPES).
     * @param  list<string>  $flyerUrls  Every flyer, the one of `$mediaUrls` first.
     */
    public function __construct(
        public ?int $id,
        public ?string $synopsisFr,
        public ?string $synopsisEn,
        public ?string $developer,
        public ?string $publisher,
        public ?int $rating,
        public ?string $players,
        public ?int $rotation,
        public ?string $resolution,
        public ?bool $joystick,
        public ?int $buttons,
        public array $genres,
        public array $mediaUrls,
        public array $flyerUrls = [],
    ) {}
}
