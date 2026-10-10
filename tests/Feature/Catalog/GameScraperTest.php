<?php

use App\Models\Game;
use App\Models\GameDetail;
use App\Models\GameMedia;
use App\Models\Member;
use App\Services\ScreenScraper\GameScraper;
use App\Services\ScreenScraper\ScrapeResult;
use App\Services\ScreenScraper\ScreenScraperClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

// Game pages completed with ScreenScraper (docs/DECISIONS.md D68).

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
    config([
        'screenscraper.dev_id' => 'dev', 'screenscraper.dev_password' => 'dev-secret',
        'screenscraper.user' => 'user', 'screenscraper.password' => 'user-secret',
    ]);
});

/** The smallest PNG there is: what a media download answers. */
function tinyPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

/**
 * A `jeuInfos` answer, as ScreenScraper writes it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jeuInfos(array $overrides = []): array
{
    return ['response' => ['jeu' => [
        'id' => '4532',
        'editeur' => ['id' => '79', 'text' => 'Namco'],
        'developpeur' => ['id' => '79', 'text' => 'Namco'],
        'joueurs' => ['text' => '1-2'],
        'note' => ['text' => '18'],
        'rotation' => '90',
        'resolution' => '224x288',
        'couleurs' => [
            ['id' => '1', 'controle' => 'P1_COIN', 'hexa' => 'FF0000'],
            ['id' => '2', 'controle' => 'P1_JOYSTICK', 'hexa' => '000000'],
            ['id' => '3', 'controle' => 'P1_BUTTON1', 'hexa' => 'FFFFFF'],
            ['id' => '4', 'controle' => 'P1_BUTTON2', 'hexa' => 'FFFFFF'],
            ['id' => '5', 'controle' => 'P2_BUTTON3', 'hexa' => 'FFFFFF'],
            ['id' => '6', 'controle' => 'P1_BUTTON1', 'hexa' => 'FF0000'],
        ],
        'synopsis' => [
            ['langue' => 'en', 'text' => 'Eat the &quot;pellets&quot; &amp; run.'],
            ['langue' => 'fr', 'text' => 'Mange les pastilles.'],
        ],
        'genres' => [
            ['id' => '1', 'noms' => [['langue' => 'en', 'text' => 'Maze'], ['langue' => 'fr', 'text' => 'Labyrinthe']]],
            ['id' => '2', 'noms' => [['langue' => 'fr', 'text' => 'Action']]],
        ],
        'medias' => [
            ['type' => 'ss', 'region' => 'us', 'url' => 'https://media.test/ss-us'],
            ['type' => 'ss', 'region' => 'wor', 'url' => 'https://media.test/ss-wor'],
            ['type' => 'wheel', 'region' => 'jp', 'url' => 'https://media.test/wheel-jp'],
            ['type' => 'wheel', 'region' => 'eu', 'url' => 'https://media.test/wheel-eu'],
            ['type' => 'video', 'region' => 'wor', 'url' => 'https://media.test/video'],
            ['type' => 'flyer', 'region' => 'wor', 'url' => 'https://media.test/flyer'],
            // Some medias have no region at all.
            ['type' => 'marquee', 'url' => 'https://media.test/marquee'],
        ],
        ...$overrides,
    ]]];
}

describe('scraping a game', function () {
    it('stores what ScreenScraper knows, and the pictures', function () {
        Http::fake([
            ScreenScraperClient::GAME_URL.'*' => Http::response(jeuInfos()),
            'https://media.test/flyer' => Http::response('<html>not an image</html>'),
            'https://media.test/*' => Http::response(tinyPng()),
        ]);
        $game = Game::factory()->create(['romname' => 'pacman']);

        $result = app(GameScraper::class)->scrape($game);

        expect($result->status)->toBe(ScrapeResult::FOUND);
        $detail = GameDetail::query()->where('game_id', $game->id)->sole();
        expect($detail->found)->toBeTrue()
            ->and($detail->screenscraper_id)->toBe(4532)
            ->and($detail->synopsis_fr)->toBe('Mange les pastilles.')
            ->and($detail->synopsis_en)->toBe('Eat the "pellets" & run.')
            ->and($detail->developer)->toBe('Namco')
            ->and($detail->publisher)->toBe('Namco')
            ->and($detail->rating)->toBe(18)
            ->and($detail->players)->toBe('1-2')
            ->and($detail->rotation)->toBe(90)
            ->and($detail->resolution)->toBe('224x288')
            ->and($detail->joystick)->toBeTrue()
            ->and($detail->buttons)->toBe(2)
            ->and($detail->genres)->toBe(['Maze', 'Action']);

        // The world picture first; no video; what is not an image is left out.
        $media = GameMedia::query()->where('game_id', $game->id)->orderBy('type')->get();
        expect($media->pluck('type')->all())->toBe(['logo', 'marquee', 'screenshot'])
            ->and($media[2]->path)->toBe('game-media/pacman/screenshot.png')
            ->and($media[2]->mime)->toBe('image/png')
            ->and($media[2]->hash)->toBe(hash('sha256', tinyPng()));
        Storage::disk('local')->assertExists('game-media/pacman/screenshot.png');
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://media.test/ss-wor');
        Http::assertNotSent(fn (HttpRequest $request): bool => in_array($request->url(), ['https://media.test/ss-us', 'https://media.test/wheel-jp', 'https://media.test/video'], true));
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), ScreenScraperClient::GAME_URL)
            && $request['romnom'] === 'pacman.zip' && $request['systemeid'] === '75'
            && $request['devid'] === 'dev' && $request['ssid'] === 'user' && $request['output'] === 'json');
    });

    it('keeps every flyer of a game, in order and once each (D73)', function () {
        $flyers = fn (array $names): array => array_map(fn (array $flyer): array => ['type' => 'flyer', 'region' => $flyer[0], 'url' => 'https://media.test/'.$flyer[1]], $names);
        // Three different pictures: the same bytes with a different tail.
        $picture = fn (string $tail): string => tinyPng().$tail;
        Http::fake([
            ScreenScraperClient::GAME_URL.'*' => Http::sequence()
                ->push(jeuInfos(['medias' => $flyers([['jp', 'jp'], ['wor', 'front'], ['wor', 'back'], ['us', 'front-again']])]))
                ->push(jeuInfos(['medias' => $flyers([['wor', 'front']])])),
            'https://media.test/front' => Http::response($picture('front')),
            'https://media.test/front-again' => Http::response($picture('front')),
            'https://media.test/back' => Http::response($picture('back')),
            'https://media.test/jp' => Http::response($picture('jp')),
        ]);
        $game = Game::factory()->create(['romname' => 'pengo']);
        $stored = fn () => GameMedia::query()->where('game_id', $game->id)->orderBy('position')->get();

        app(GameScraper::class)->scrape($game);

        // The world's first, in ScreenScraper's order, then Japan's; the one sent twice is kept once.
        expect($stored()->pluck('path')->all())->toBe(['game-media/pengo/flyer.png', 'game-media/pengo/flyer-1.png', 'game-media/pengo/flyer-2.png'])
            ->and($stored()->pluck('hash')->all())->toBe(array_map(fn (string $tail): string => hash('sha256', $picture($tail)), ['front', 'back', 'jp']));

        // A later answer with fewer flyers: the others go, with their files.
        app(GameScraper::class)->scrape($game);

        expect($stored()->pluck('position')->all())->toBe([0]);
        Storage::disk('local')->assertExists('game-media/pengo/flyer.png');
        Storage::disk('local')->assertMissing('game-media/pengo/flyer-1.png');
        Storage::disk('local')->assertMissing('game-media/pengo/flyer-2.png');
    });

    it('remembers a game ScreenScraper does not know', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response('Erreur : Rom/Iso/Dossier non trouvée !', 404)]);
        $game = Game::factory()->create();

        expect(app(GameScraper::class)->scrape($game)->status)->toBe(ScrapeResult::NOT_FOUND)
            ->and(GameDetail::query()->where('game_id', $game->id)->sole()->found)->toBeFalse();
    });

    it('stores nothing when the quota is spent or ScreenScraper fails', function (int $status, string $body, string $expected) {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response($body, $status)]);

        expect(app(GameScraper::class)->scrape(Game::factory()->create())->status)->toBe($expected)
            ->and(GameDetail::query()->count())->toBe(0);
    })->with([
        'quota, answered 200' => [200, 'Votre quota de scrape est dépassé pour aujourd\'hui !', ScrapeResult::QUOTA],
        'too many threads' => [429, 'Le nombre de threads autorisé pour le membre est atteint', ScrapeResult::QUOTA],
        'server error' => [500, 'oops', ScrapeResult::ERROR],
        'closed API' => [423, 'API totalement fermée', ScrapeResult::ERROR],
    ]);

    it('reads an answer with missing or odd fields', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response(jeuInfos([
            'note' => null, 'rotation' => 'abc', 'synopsis' => 'none', 'genres' => null, 'medias' => null, 'couleurs' => null, 'editeur' => ['text' => '  '],
        ]))]);
        $game = Game::factory()->create();

        app(GameScraper::class)->scrape($game);

        $detail = GameDetail::query()->where('game_id', $game->id)->sole();
        expect($detail->found)->toBeTrue()
            ->and($detail->rating)->toBeNull()
            ->and($detail->rotation)->toBeNull()
            ->and($detail->synopsis_fr)->toBeNull()
            ->and($detail->publisher)->toBeNull()
            ->and($detail->buttons)->toBeNull()
            ->and($detail->genres)->toBe([])
            ->and(GameMedia::query()->count())->toBe(0);
    });

    it('waits between two calls', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response('nope', 404)]);
        $scraper = app(GameScraper::class);

        $scraper->scrape(Game::factory()->create());
        $scraper->scrape(Game::factory()->create());

        Sleep::assertSleptTimes(1);
    });
});

describe('catalog:scrape', function () {
    it('asks for the games with scores first, then those never asked, a few at a time', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response('nope', 404)]);
        Game::factory()->create(['romname' => 'aaa']);
        $played = Game::factory()->create(['romname' => 'zzz']);
        leaderboardScore($played, publicPlayer('ACE'), 100);
        $recent = Game::factory()->create(['romname' => 'bbb']);
        GameDetail::query()->create(['game_id' => $recent->id, 'found' => true, 'scraped_at' => now()->subDay()]);
        $old = Game::factory()->create(['romname' => 'ccc']);
        GameDetail::query()->create(['game_id' => $old->id, 'found' => true, 'scraped_at' => now()->subDays(40)]);

        expect(app(GameScraper::class)->pending()->pluck('romname')->all())->toBe(['zzz', 'aaa', 'ccc'])
            ->and(app(GameScraper::class)->pending(force: true)->pluck('romname')->all())->toBe(['zzz', 'aaa', 'ccc', 'bbb']);

        $this->artisan('catalog:scrape', ['--limit' => 2])
            ->expectsOutputToContain('0 found, 2 unknown to ScreenScraper, 0 errors.')
            ->assertSuccessful();
        expect(GameDetail::query()->where('found', false)->count())->toBe(2);
    });

    it('asks for the games named, even recently asked', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response(jeuInfos(['medias' => []]))]);
        $game = Game::factory()->create(['romname' => 'pacman']);
        GameDetail::query()->create(['game_id' => $game->id, 'found' => false, 'scraped_at' => now()]);
        Game::factory()->create(['romname' => 'other']);

        $this->artisan('catalog:scrape', ['romname' => ['pacman']])->assertSuccessful();

        expect(GameDetail::query()->sole()->found)->toBeTrue();
    });

    it('stops when ScreenScraper refuses more', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response('quota dépassé', 200)]);
        Game::factory()->count(3)->create();

        $this->artisan('catalog:scrape')->expectsOutputToContain('Stopped at')->assertSuccessful();

        Http::assertSentCount(1);
    });

    it('refuses to run without credentials', function () {
        config(['screenscraper.dev_id' => null]);

        $this->artisan('catalog:scrape')->expectsOutputToContain('ScreenScraper is not configured')->assertFailed();
    });
});

describe('game page', function () {
    beforeEach(fn () => $this->actingAs(Member::factory()->create(), 'member'));

    it('shows the details and the pictures of a scraped game', function () {
        Http::fake([
            ScreenScraperClient::GAME_URL.'*' => Http::response(jeuInfos()),
            'https://media.test/*' => Http::response(tinyPng()),
        ]);
        $game = Game::factory()->create(['romname' => 'pacman']);
        app(GameScraper::class)->scrape($game);
        $hash = hash('sha256', tinyPng());

        $this->getJson('/api/v1/front/games/pacman')->assertOk()
            ->assertJsonPath('details', [
                'synopsis' => ['fr' => 'Mange les pastilles.', 'en' => 'Eat the "pellets" & run.'],
                'developer' => 'Namco', 'publisher' => 'Namco', 'rating' => 18, 'players' => '1-2',
                'rotation' => 90, 'resolution' => '224x288', 'controls' => ['joystick' => true, 'buttons' => 2],
                'genres' => ['Maze', 'Action'],
            ])
            ->assertJsonPath('media', ['flyer' => $hash, 'logo' => $hash, 'marquee' => $hash, 'screenshot' => $hash]);

        $this->get('/api/v1/front/games/pacman/media/screenshot')
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('ETag', '"'.$hash.'"');
        $this->get('/api/v1/front/games/pacman/media/screenshot', ['If-None-Match' => '"'.$hash.'"'])->assertStatus(304);
        $this->getJson('/api/v1/front/games/pacman/media/title')->assertNotFound()->assertJsonPath('code', 'media_not_found');
        $this->getJson('/api/v1/front/games/pacman/media/video')->assertNotFound()->assertJsonPath('code', 'media_not_found');
    });

    it('has no details for a game never asked, or unknown to ScreenScraper', function () {
        Game::factory()->create(['romname' => 'never']);
        $unknown = Game::factory()->create(['romname' => 'unknown']);
        GameDetail::query()->create(['game_id' => $unknown->id, 'found' => false, 'scraped_at' => now()]);

        foreach (['never', 'unknown'] as $romname) {
            $response = $this->getJson('/api/v1/front/games/'.$romname)->assertOk()->assertJsonPath('details', null);
            expect($response->json('media'))->toBe([]);
        }
    });
});
