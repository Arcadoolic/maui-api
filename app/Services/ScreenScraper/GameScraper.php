<?php

namespace App\Services\ScreenScraper;

use App\Models\Game;
use App\Models\GameDetail;
use App\Models\GameMedia;
use App\Services\Leaderboards\Leaderboards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Completes the games with what ScreenScraper knows (docs/DECISIONS.md D68):
 * texts in `game_details`, pictures on the `local` disk; what the game's sheet lacks comes
 * from its media donor (D99).
 */
final class GameScraper
{
    /** Content types kept, with the extension of their file. */
    private const IMAGES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private readonly ScreenScraperClient $client) {}

    /**
     * Games to ask for, most useful first: those with a visible score, then
     * those never asked, then the oldest answers.
     *
     * @return Builder<Game>
     */
    public function pending(bool $force = false): Builder
    {
        $withScores = Leaderboards::visibleScores()->select('scores.game_id')->distinct();

        return Game::query()
            ->leftJoin('game_details', 'game_details.game_id', '=', 'games.id')
            ->leftJoinSub($withScores, 'played', 'played.game_id', '=', 'games.id')
            ->select('games.*')
            ->when(! $force, fn (Builder $query) => $query->where(fn (Builder $due) => $due
                ->whereNull('game_details.id')
                ->orWhere('game_details.scraped_at', '<', now()->subDays((int) config('screenscraper.refresh_after_days')))))
            ->orderByRaw('played.game_id is null')
            ->orderByRaw('game_details.scraped_at asc nulls first')
            ->orderBy('games.romname');
    }

    /**
     * Asks for one game and stores the answer. Nothing is stored on a quota or an error.
     *
     * The types of pictures the game's own sheet has none of come from its media donor, when it
     * has one (D99); its priority region comes first for both (D99).
     */
    public function scrape(Game $game): ScrapeResult
    {
        $detail = GameDetail::query()->where('game_id', $game->id)->first();
        $region = $detail?->media_region;
        $result = $this->client->game($game->romname, $region);

        if ($result->status === ScrapeResult::NOT_FOUND) {
            // Kept: not to ask again tomorrow. What a former answer gave stays.
            GameDetail::query()->updateOrCreate(['game_id' => $game->id], ['found' => false, 'scraped_at' => now()]);
        }
        $own = [];
        if ($result->status === ScrapeResult::FOUND && $result->game !== null) {
            $scraped = $result->game;
            GameDetail::query()->updateOrCreate(['game_id' => $game->id], [
                'found' => true,
                'screenscraper_id' => $scraped->id,
                'synopsis_fr' => $scraped->synopsisFr,
                'synopsis_en' => $scraped->synopsisEn,
                'developer' => $scraped->developer,
                'publisher' => $scraped->publisher,
                'rating' => $scraped->rating,
                'players' => $scraped->players === null ? null : mb_substr($scraped->players, 0, 32),
                'rotation' => $scraped->rotation,
                'resolution' => $scraped->resolution === null ? null : mb_substr($scraped->resolution, 0, 32),
                'joystick' => $scraped->joystick,
                'buttons' => $scraped->buttons,
                'genres' => $scraped->genres,
                'scraped_at' => now(),
            ]);
            $own = $this->storeAll($game, $scraped, array_keys(GameMedia::TYPES), donor: false);
        }
        if (! in_array($result->status, [ScrapeResult::FOUND, ScrapeResult::NOT_FOUND], true)) {
            return $result;
        }

        return $this->completeFromDonor($game, $result, $detail?->media_donor_id, $region, array_values(array_diff(array_keys(GameMedia::TYPES), $own)));
    }

    /**
     * The types the game has none of, from its media donor (D99). The donor's pictures of a type
     * it no longer fills go: the donor was taken away, or the game has its own now.
     *
     * @param  list<string>  $missing
     */
    private function completeFromDonor(Game $game, ScrapeResult $result, ?int $donorId, ?string $region, array $missing): ScrapeResult
    {
        $filled = [];
        if ($donorId !== null && $missing !== []) {
            $donor = $this->client->gameById($donorId, $region);
            if ($donor->status === ScrapeResult::NOT_FOUND) {
                return new ScrapeResult($result->status, $result->game, "media donor {$donorId} unknown to ScreenScraper");
            }
            if ($donor->status !== ScrapeResult::FOUND || $donor->game === null) {
                return new ScrapeResult($donor->status, $result->game, "media donor {$donorId}: ".($donor->message ?? $donor->status));
            }
            $filled = $this->storeAll($game, $donor->game, $missing, donor: true);
        }
        $gone = GameMedia::query()->where('game_id', $game->id)->where('donor', true)
            ->whereIn('type', array_values(array_diff($missing, $filled)))->get();
        foreach ($gone as $media) {
            Storage::disk(GameMedia::DISK)->delete($media->path);
            $media->delete();
        }

        return $result;
    }

    /**
     * The pictures of these types a sheet gives.
     *
     * @param  list<string>  $types
     * @return list<string> The types it gave a picture of.
     */
    private function storeAll(Game $game, ScrapedGame $scraped, array $types, bool $donor): array
    {
        $stored = [];
        foreach ($types as $type) {
            $done = in_array($type, GameMedia::SEVERAL, true)
                ? $this->storeSeveral($game, $type, $scraped->severalUrls[$type] ?? [], $donor)
                : isset($scraped->mediaUrls[$type]) && $this->storeMedia($game, $type, $scraped->mediaUrls[$type], $donor);
            if ($done) {
                $stored[] = $type;
            }
        }

        return $stored;
    }

    /** A media that cannot be had, or is not an image, is left as it was. */
    private function storeMedia(Game $game, string $type, string $url, bool $donor): bool
    {
        $image = $this->image($url);
        if ($image !== null) {
            $this->store($game, $type, 0, $donor, ...$image);
        }

        return $image !== null;
    }

    /**
     * Every flyer (D73) or logo (D88) of the game, in the order given: the same picture twice (one
     * file under two regions) is kept once, and the pictures of a former answer past the last one
     * are removed. When none can be had, the former ones stay.
     *
     * @param  list<string>  $urls
     */
    private function storeSeveral(Game $game, string $type, array $urls, bool $donor): bool
    {
        $hashes = [];
        foreach (array_slice($urls, 0, GameMedia::MAX_SEVERAL) as $url) {
            $image = $this->image($url);
            if ($image !== null && ! in_array($hash = hash('sha256', $image[0]), $hashes, true)) {
                $this->store($game, $type, count($hashes), $donor, ...$image);
                $hashes[] = $hash;
            }
        }
        if ($hashes === []) {
            return false;
        }
        $stale = GameMedia::query()->where('game_id', $game->id)->where('type', $type)->where('position', '>=', count($hashes))->get();
        foreach ($stale as $media) {
            Storage::disk(GameMedia::DISK)->delete($media->path);
            $media->delete();
        }

        return true;
    }

    /**
     * The downloaded file and its type, null when it cannot be had or is not an image.
     *
     * @return array{string, string}|null
     */
    private function image(string $url): ?array
    {
        $content = $this->client->download($url);
        $mime = $content === null ? null : (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);

        return $content === null || ! is_string($mime) || ! isset(self::IMAGES[$mime]) ? null : [$content, $mime];
    }

    private function store(Game $game, string $type, int $position, bool $donor, string $content, string $mime): void
    {
        // The first picture keeps the name it always had; the others are numbered.
        $name = $position === 0 ? $type : "{$type}-{$position}";
        $path = "game-media/{$game->romname}/{$name}.".self::IMAGES[$mime];
        $former = GameMedia::query()->where('game_id', $game->id)->where('type', $type)->where('position', $position)->value('path');
        if (is_string($former) && $former !== $path) {
            Storage::disk(GameMedia::DISK)->delete($former);
        }
        Storage::disk(GameMedia::DISK)->put($path, $content);
        GameMedia::query()->updateOrCreate(
            ['game_id' => $game->id, 'type' => $type, 'position' => $position],
            ['donor' => $donor, 'path' => $path, 'mime' => $mime, 'hash' => hash('sha256', $content)],
        );
    }
}
