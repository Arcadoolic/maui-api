<?php

namespace App\Http\Controllers\Api;

use App\Http\Problems\ApiProblemException;
use App\Models\Game;
use App\Models\GameDetail;
use App\Services\ScreenScraper\GameMediaFiles;
use App\Services\ScreenScraper\GameScraper;
use App\Services\ScreenScraper\ScrapeResult;
use App\Services\ScreenScraper\ScreenScraperClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pictures of the games for the cabinets and the pack manager, chosen once by the API
 * (docs/DECISIONS.md D99): the same everywhere they are shown.
 */
final class GameMediaController
{
    public function __construct(private readonly GameMediaFiles $files) {}

    /** Every game's first marquee, flyer and logo, by hash: a reader downloads what differs. */
    public function index(): JsonResponse
    {
        return new JsonResponse(['games' => (object) $this->files->cabinetHashes()]);
    }

    public function show(Request $request, string $romname, string $type): Response
    {
        return $this->files->response($request, $romname, $type);
    }

    /**
     * Asks ScreenScraper for one game now, for a pack being made: the game is created when the
     * API does not know it yet, as a score does.
     */
    public function scrape(string $romname, GameScraper $scraper, ScreenScraperClient $client): JsonResponse
    {
        if (preg_match(GameMediaFiles::ROMNAME, $romname) !== 1) {
            throw ApiProblemException::gameNotFound();
        }
        if (! $client->isConfigured()) {
            throw ApiProblemException::screenScraperUnavailable();
        }
        $game = Game::query()->firstOrCreate(['romname' => $romname], ['description' => $romname]);
        $result = $scraper->scrape($game);
        if (! in_array($result->status, [ScrapeResult::FOUND, ScrapeResult::NOT_FOUND], true)) {
            throw ApiProblemException::screenScraperUnavailable();
        }
        $detail = GameDetail::query()->where('game_id', $game->id)->first();

        return new JsonResponse([
            'status' => $result->status,
            'publisher' => $detail?->publisher,
            'developer' => $detail?->developer,
            'media' => (object) $this->files->hashes($game),
        ]);
    }
}
