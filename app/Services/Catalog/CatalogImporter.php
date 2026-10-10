<?php

namespace App\Services\Catalog;

use App\Enums\CategorySource;
use App\Models\Category;
use App\Models\Game;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upsert of a catalog batch, keyed by romname. Never deletes: a
 * game missing from a push keeps its scores (docs/DECISIONS.md D47).
 */
final class CatalogImporter
{
    /** @var array<string, int> Category ids of this batch, by source, parent and name. */
    private array $categoryIds = [];

    /**
     * @param  list<CatalogGameData>  $games
     */
    public function import(array $games): CatalogImportResult
    {
        $this->categoryIds = [];

        return DB::transaction(function () use ($games): CatalogImportResult {
            $result = new CatalogImportResult;
            $existing = Game::query()
                ->whereIn('romname', array_map(fn (CatalogGameData $game): string => $game->romname, $games))
                ->get()
                ->keyBy('romname');

            foreach ($games as $data) {
                $game = $existing->get($data->romname) ?? new Game(['romname' => $data->romname]);
                $game->fill($this->attributes($data));

                if (! $game->exists) {
                    $result->created++;
                } elseif (! $game->isCatalogued() || $game->isDirty()) {
                    $result->updated++;
                } else {
                    $result->unchanged++;

                    continue;
                }

                $game->catalogued_at ??= now();
                $game->save();
            }

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(CatalogGameData $data): array
    {
        return [
            'description' => $data->description,
            'manufacturer' => $data->manufacturer,
            'year' => $data->year,
            'parent_romname' => $data->parentRomname,
            'player_sim' => $data->playerSim,
            'player_alt' => $data->playerAlt,
            'genre_category_id' => $data->genre !== null
                ? $this->categoryId(CategorySource::Genre, $data->genre)
                : null,
            'catver_category_id' => $this->catverCategoryId($data),
            'mature' => $data->mature,
            // Left out by a sender that does not know: the stored value stays (D74).
            ...($data->hiscores !== null ? ['hiscores' => $data->hiscores] : []),
        ];
    }

    /** The subgenre, under its genre; the genre itself when there is no subgenre. */
    private function catverCategoryId(CatalogGameData $data): ?int
    {
        if ($data->catverGenre === null) {
            return null;
        }

        $genreId = $this->categoryId(CategorySource::Catver, $data->catverGenre);

        return $data->catverSubgenre !== null
            ? $this->categoryId(CategorySource::Catver, $data->catverSubgenre, $genreId)
            : $genreId;
    }

    private function categoryId(CategorySource $source, string $name, ?int $parentId = null): int
    {
        $key = $source->value.'|'.$parentId.'|'.$name;

        return $this->categoryIds[$key] ??= Category::query()->firstOrCreate([
            'source' => $source,
            'parent_id' => $parentId,
            'name' => $name,
        ])->id;
    }
}
