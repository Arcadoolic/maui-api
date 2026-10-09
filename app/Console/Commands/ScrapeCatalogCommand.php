<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\ScreenScraper\GameScraper;
use App\Services\ScreenScraper\ScrapeResult;
use App\Services\ScreenScraper\ScreenScraperClient;
use Illuminate\Console\Command;

/**
 * Completes the game pages of the hiscores front with ScreenScraper
 * (docs/DECISIONS.md D68). Meant to run every day, a few games at a time:
 * the calls count against a daily quota.
 */
final class ScrapeCatalogCommand extends Command
{
    protected $signature = 'catalog:scrape
        {romname?* : Only these games, asked again even when their answer is recent}
        {--limit=50 : Games asked at most}
        {--force : Ask again the games whose answer is recent}';

    protected $description = 'Complete the games with ScreenScraper: texts and pictures';

    public function handle(GameScraper $scraper, ScreenScraperClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('ScreenScraper is not configured: set SCREENSCRAPER_DEV_ID, SCREENSCRAPER_DEV_PASSWORD, SCREENSCRAPER_USER and SCREENSCRAPER_PASSWORD.');

            return self::FAILURE;
        }

        $romnames = (array) $this->argument('romname');
        $games = $romnames === []
            ? $scraper->pending((bool) $this->option('force'))->limit(max(1, (int) $this->option('limit')))->get()
            : Game::query()->whereIn('romname', $romnames)->orderBy('romname')->get();

        $counts = [ScrapeResult::FOUND => 0, ScrapeResult::NOT_FOUND => 0, ScrapeResult::ERROR => 0];
        foreach ($games as $game) {
            $result = $scraper->scrape($game);
            if ($result->status === ScrapeResult::QUOTA) {
                $this->warn("Stopped at {$game->romname}, ScreenScraper refuses more for now: {$result->message}");
                break;
            }
            $counts[$result->status]++;
            $this->line(sprintf('%-12s %s%s', $game->romname, $result->status, $result->message === null ? '' : ' ('.$result->message.')'));
        }
        $this->info(sprintf('%d found, %d unknown to ScreenScraper, %d errors.', ...array_values($counts)));

        return self::SUCCESS;
    }
}
