<?php

namespace App\Services\Catalog;

/**
 * One validated catalog entry. A field the push leaves out is null: each
 * push describes the game completely (docs/DECISIONS.md D47).
 */
final readonly class CatalogGameData
{
    public function __construct(
        public string $romname,
        public string $description,
        public ?string $manufacturer = null,
        public ?string $year = null,
        public ?string $parentRomname = null,
        public ?int $playerSim = null,
        public ?int $playerAlt = null,
        public ?string $genre = null,
        public ?string $catverGenre = null,
        public ?string $catverSubgenre = null,
        public bool $mature = false,
    ) {}

    /**
     * @param  array<string, mixed>  $game  Validated by PutCatalogGamesRequest.
     */
    public static function fromValidated(array $game): self
    {
        return new self(
            romname: self::string($game, 'romname') ?? '',
            description: self::string($game, 'description') ?? '',
            manufacturer: self::string($game, 'manufacturer'),
            year: self::string($game, 'year'),
            parentRomname: self::string($game, 'parent_romname'),
            playerSim: self::int($game, 'player_sim'),
            playerAlt: self::int($game, 'player_alt'),
            genre: self::string($game, 'genre'),
            catverGenre: self::string($game, 'catver_genre'),
            catverSubgenre: self::string($game, 'catver_subgenre'),
            mature: filter_var($game['mature'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /** @param  array<string, mixed>  $game */
    private static function string(array $game, string $key): ?string
    {
        $value = $game[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param  array<string, mixed>  $game */
    private static function int(array $game, string $key): ?int
    {
        $value = $game[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
