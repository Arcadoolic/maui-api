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
 * texts in `game_details`, pictures on the `local` disk.
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

    /** Asks for one game and stores the answer. Nothing is stored on a quota or an error. */
    public function scrape(Game $game): ScrapeResult
    {
        $result = $this->client->game($game->romname);

        if ($result->status === ScrapeResult::NOT_FOUND) {
            // Kept: not to ask again tomorrow. What a former answer gave stays.
            GameDetail::query()->updateOrCreate(['game_id' => $game->id], ['found' => false, 'scraped_at' => now()]);
        }
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
            foreach ($scraped->mediaUrls as $type => $url) {
                if ($type !== 'flyer') {
                    $this->storeMedia($game, $type, $url);
                }
            }
            $this->storeFlyers($game, $scraped->flyerUrls);
        }

        return $result;
    }

    /** A media that cannot be had, or is not an image, is left as it was. */
    private function storeMedia(Game $game, string $type, string $url): void
    {
        $image = $this->image($url);
        if ($image !== null) {
            $this->store($game, $type, 0, ...$image);
        }
    }

    /**
     * Every flyer of the game (D73), in the order given: the same picture twice (one file under
     * two regions) is kept once, and the flyers of a former answer past the last one are removed.
     * When none can be had, the former ones stay.
     *
     * @param  list<string>  $urls
     */
    private function storeFlyers(Game $game, array $urls): void
    {
        $hashes = [];
        foreach (array_slice($urls, 0, GameMedia::MAX_FLYERS) as $url) {
            $image = $this->image($url);
            if ($image !== null && ! in_array($hash = hash('sha256', $image[0]), $hashes, true)) {
                $this->store($game, 'flyer', count($hashes), ...$image);
                $hashes[] = $hash;
            }
        }
        if ($hashes === []) {
            return;
        }
        $stale = GameMedia::query()->where('game_id', $game->id)->where('type', 'flyer')->where('position', '>=', count($hashes))->get();
        foreach ($stale as $media) {
            Storage::disk(GameMedia::DISK)->delete($media->path);
            $media->delete();
        }
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

    private function store(Game $game, string $type, int $position, string $content, string $mime): void
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
            ['path' => $path, 'mime' => $mime, 'hash' => hash('sha256', $content)],
        );
    }
}
