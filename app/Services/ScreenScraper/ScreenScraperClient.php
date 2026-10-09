<?php

namespace App\Services\ScreenScraper;

use App\Models\GameMedia;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * ScreenScraper's `jeuInfos` for an arcade game (docs/DECISIONS.md D68).
 * After MAUI's ScreenScraperClient.class.ts, whose findings on the answers
 * (404 in plain text, errors answered 200, regions of the medias) are kept.
 */
final class ScreenScraperClient
{
    public const GAME_URL = 'https://api.screenscraper.fr/api2/jeuInfos.php';

    /** The arcade ("Mame") system on ScreenScraper. */
    private const ARCADE_SYSTEM_ID = '75';

    /**
     * World first, then the West, then Japan: MAUI takes Japan second, but a
     * logo in Japanese says little on a page read in French or English.
     */
    private const REGIONS = ['wor', 'us', 'eu', 'jp', 'ss'];

    private const TIMEOUT_SECONDS = 30;

    private const QUOTA_PATTERN = '/quota|limite|threads/i';

    private float $lastCallAt = 0.0;

    public function isConfigured(): bool
    {
        return filled(config('screenscraper.dev_id'))
            && filled(config('screenscraper.dev_password'))
            && filled(config('screenscraper.user'))
            && filled(config('screenscraper.password'));
    }

    public function game(string $romname): ScrapeResult
    {
        $this->throttle();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get(self::GAME_URL, [
                'devid' => config('screenscraper.dev_id'),
                'devpassword' => config('screenscraper.dev_password'),
                'softname' => config('screenscraper.soft_name'),
                'ssid' => config('screenscraper.user'),
                'sspassword' => config('screenscraper.password'),
                'output' => 'json',
                'systemeid' => self::ARCADE_SYSTEM_ID,
                'romnom' => $romname.'.zip',
            ]);
        } catch (ConnectionException $exception) {
            return new ScrapeResult(ScrapeResult::ERROR, message: $exception->getMessage());
        }

        $body = $response->body();
        // An unknown rom is answered 404, in plain text.
        if ($response->status() === 404) {
            return new ScrapeResult(ScrapeResult::NOT_FOUND);
        }
        // Some errors are answered 200, in plain text too.
        $game = $response->successful() ? $response->json('response.jeu') : null;
        if (! is_array($game)) {
            return preg_match(self::QUOTA_PATTERN, $body) === 1 || in_array($response->status(), [429, 430, 431], true)
                ? new ScrapeResult(ScrapeResult::QUOTA, message: mb_substr($body, 0, 200))
                : ($response->successful() && $response->json('response') !== null
                    ? new ScrapeResult(ScrapeResult::NOT_FOUND)
                    : new ScrapeResult(ScrapeResult::ERROR, message: 'HTTP '.$response->status().': '.mb_substr($body, 0, 200)));
        }

        return new ScrapeResult(ScrapeResult::FOUND, self::read($game));
    }

    /** A media file, null when it cannot be had or is too big. */
    public function download(string $url): ?string
    {
        $this->throttle();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (ConnectionException) {
            return null;
        }
        $content = $response->body();

        return $response->successful() && $content !== '' && strlen($content) <= (int) config('screenscraper.max_media_bytes')
            ? $content
            : null;
    }

    /**
     * @param  array<string, mixed>  $game
     */
    private static function read(array $game): ScrapedGame
    {
        $medias = is_array($game['medias'] ?? null) ? $game['medias'] : [];
        $urls = [];
        foreach (GameMedia::TYPES as $type => $theirs) {
            $url = self::mediaUrl($medias, $theirs);
            if ($url !== null) {
                $urls[$type] = $url;
            }
        }
        $rating = self::text($game['note'] ?? null);
        $rotation = self::text($game['rotation'] ?? null);
        $controls = self::firstPlayerControls($game['couleurs'] ?? null);

        return new ScrapedGame(
            id: is_numeric($game['id'] ?? null) ? (int) $game['id'] : null,
            synopsisFr: self::localized($game['synopsis'] ?? null, 'fr'),
            synopsisEn: self::localized($game['synopsis'] ?? null, 'en'),
            developer: self::text($game['developpeur'] ?? null),
            publisher: self::text($game['editeur'] ?? null),
            rating: is_numeric($rating) ? max(0, min(20, (int) round((float) $rating))) : null,
            players: self::text($game['joueurs'] ?? null),
            rotation: is_numeric($rotation) ? ((int) $rotation) % 360 : null,
            resolution: self::text($game['resolution'] ?? null),
            joystick: $controls === [] ? null : in_array('JOYSTICK', $controls, true),
            buttons: $controls === [] ? null : count(array_filter($controls, fn (string $control): bool => str_starts_with($control, 'BUTTON'))),
            genres: self::genres($game['genres'] ?? null),
            mediaUrls: $urls,
        );
    }

    /**
     * The controls of the first player (`JOYSTICK`, `BUTTON1`...). ScreenScraper
     * has no field for them: they are read from the colours of the panel
     * (`couleurs`, one entry per control and colour, e.g. `P1_BUTTON1`).
     *
     * @return list<string>
     */
    private static function firstPlayerControls(mixed $colours): array
    {
        $controls = [];
        foreach (is_array($colours) ? $colours : [] as $colour) {
            $control = is_array($colour) && is_string($colour['controle'] ?? null) ? $colour['controle'] : '';
            if (str_starts_with($control, 'P1_')) {
                $controls[] = substr($control, 3);
            }
        }

        return array_values(array_unique($controls));
    }

    /** ScreenScraper writes a value as `{"text": "..."}`, or as the text itself. */
    private static function text(mixed $value): ?string
    {
        $text = is_array($value) ? ($value['text'] ?? null) : $value;
        $text = is_scalar($text) ? trim((string) $text) : '';

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    /** One text of a list of `{"langue": "fr", "text": "..."}`. */
    private static function localized(mixed $texts, string $language): ?string
    {
        foreach (is_array($texts) ? $texts : [] as $entry) {
            if (is_array($entry) && ($entry['langue'] ?? null) === $language && is_string($entry['text'] ?? null) && trim($entry['text']) !== '') {
                // Some texts come with HTML entities (`&quot;3D maze&quot;`): stored as plain text.
                return trim(html_entity_decode($entry['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return null;
    }

    /**
     * The English names of the genres, as the front is in English; the French ones failing that.
     *
     * @return list<string>
     */
    private static function genres(mixed $genres): array
    {
        $names = [];
        foreach (is_array($genres) ? $genres : [] as $genre) {
            $name = is_array($genre) ? (self::localized($genre['noms'] ?? null, 'en') ?? self::localized($genre['noms'] ?? null, 'fr')) : null;
            if ($name !== null) {
                $names[] = mb_substr($name, 0, 128);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<int|string, mixed>  $medias
     */
    private static function mediaUrl(array $medias, string $type): ?string
    {
        $candidates = array_values(array_filter($medias, fn (mixed $media): bool => is_array($media)
            && ($media['type'] ?? null) === $type
            && is_string($media['url'] ?? null)
            && str_starts_with($media['url'], 'https://')));
        foreach (self::REGIONS as $region) {
            foreach ($candidates as $media) {
                if (($media['region'] ?? null) === $region) {
                    return $media['url'];
                }
            }
        }

        return $candidates[0]['url'] ?? null;
    }

    private function throttle(): void
    {
        $wait = (int) config('screenscraper.throttle_ms') - (int) ((microtime(true) - $this->lastCallAt) * 1000);
        if ($this->lastCallAt > 0 && $wait > 0) {
            Sleep::for($wait)->milliseconds();
        }
        $this->lastCallAt = microtime(true);
    }
}
