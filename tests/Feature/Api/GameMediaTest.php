<?php

use App\Models\Game;
use App\Models\GameDetail;
use App\Models\GameMedia;
use App\Services\ScreenScraper\ScreenScraperClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

// The pictures of the games, read by the cabinets and the pack manager (docs/DECISIONS.md D99).

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
});

function storedMedia(Game $game, string $type, string $content, int $position = 0, string $mime = 'image/png'): GameMedia
{
    $path = "game-media/{$game->romname}/{$type}-{$position}";
    Storage::disk('local')->put($path, $content);

    return GameMedia::query()->create([
        'game_id' => $game->id, 'type' => $type, 'position' => $position,
        'path' => $path, 'mime' => $mime, 'hash' => hash('sha256', $content),
    ]);
}

describe('the list of pictures', function () {
    it('gives each game\'s marquee, flyer and logo, the first of each', function () {
        $pacman = Game::factory()->create(['romname' => 'pacman']);
        storedMedia($pacman, 'marquee', 'm');
        storedMedia($pacman, 'flyer', 'f0', mime: 'image/jpeg');
        storedMedia($pacman, 'flyer', 'f1', 1);
        storedMedia($pacman, 'screenshot', 's');
        $tetris = Game::factory()->create(['romname' => 'tetris']);
        storedMedia($tetris, 'logo', 'l');
        Game::factory()->create(['romname' => 'nothing']);
        [$client, $token] = cabinetWithToken();

        $response = $this->getJson('/api/v1/media', cabinetHeaders($client, $token))->assertOk();

        expect($response->json('games'))->toBe([
            'pacman' => ['flyer' => hash('sha256', 'f0'), 'marquee' => hash('sha256', 'm')],
            'tetris' => ['logo' => hash('sha256', 'l')],
        ]);
        // Unchanged since: nothing to download.
        $this->getJson('/api/v1/media', [...cabinetHeaders($client, $token), 'If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    });

    it('is read by the pack manager\'s service account, not by a bot', function () {
        storedMedia(Game::factory()->create(['romname' => 'pacman']), 'marquee', 'm');
        [$service, $serviceToken] = serviceWithToken();
        [$bot, $botToken] = botWithToken();

        $this->getJson('/api/v1/catalog/media', serviceHeaders($service, $serviceToken))
            ->assertOk()->assertJsonPath('games.pacman.marquee', hash('sha256', 'm'));
        $this->getJson('/api/v1/catalog/media', serviceHeaders($bot, $botToken))->assertForbidden();
        $this->getJson('/api/v1/media')->assertUnauthorized();
    });
});

describe('a picture', function () {
    it('is served with its hash as ETag', function () {
        $game = Game::factory()->create(['romname' => 'pacman']);
        storedMedia($game, 'flyer', 'jpeg bytes', mime: 'image/jpeg');
        [$client, $token] = cabinetWithToken();
        $hash = hash('sha256', 'jpeg bytes');

        $this->get('/api/v1/games/pacman/media/flyer', cabinetHeaders($client, $token))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('ETag', '"'.$hash.'"');
        $this->get('/api/v1/games/pacman/media/flyer', [...cabinetHeaders($client, $token), 'If-None-Match' => '"'.$hash.'"'])
            ->assertStatus(304);
        $this->getJson('/api/v1/games/pacman/media/logo', cabinetHeaders($client, $token))
            ->assertNotFound()->assertJsonPath('code', 'media_not_found');

        [$service, $serviceToken] = serviceWithToken();
        $this->get('/api/v1/catalog/games/pacman/media/flyer', serviceHeaders($service, $serviceToken))
            ->assertOk()->assertHeader('ETag', '"'.$hash.'"');
    });
});

describe('scraping on demand', function () {
    beforeEach(fn () => config([
        'screenscraper.dev_id' => 'dev', 'screenscraper.dev_password' => 'dev-secret',
        'screenscraper.user' => 'user', 'screenscraper.password' => 'user-secret',
    ]));

    it('asks ScreenScraper for a game the API does not know yet, and answers its pictures', function () {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        Http::fake([
            ScreenScraperClient::GAME_URL.'*' => Http::response(['response' => ['jeu' => [
                'id' => '12', 'editeur' => ['text' => 'Sega'], 'developpeur' => ['text' => 'Sega'],
                'medias' => [['type' => 'marquee', 'region' => 'wor', 'url' => 'https://media.test/marquee']],
            ]]]),
            'https://media.test/*' => Http::response($png),
        ]);
        [$service, $token] = serviceWithToken();

        $this->postJson('/api/v1/catalog/games/tetris/scrape', [], serviceHeaders($service, $token))
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('publisher', 'Sega')
            ->assertJsonPath('developer', 'Sega')
            ->assertJsonPath('media', ['marquee' => hash('sha256', $png)]);

        $game = Game::query()->where('romname', 'tetris')->sole();
        expect($game->description)->toBe('tetris')
            ->and(GameDetail::query()->where('game_id', $game->id)->sole()->found)->toBeTrue();
    });

    it('answers the quota, refuses a bad romname and a cabinet', function () {
        Http::fake([ScreenScraperClient::GAME_URL.'*' => Http::response('Votre quota de scrape est dépassé pour aujourd\'hui !')]);
        [$service, $token] = serviceWithToken();
        [$client, $cabinetToken] = cabinetWithToken();

        $this->postJson('/api/v1/catalog/games/tetris/scrape', [], serviceHeaders($service, $token))
            ->assertStatus(503)->assertJsonPath('code', 'screenscraper_unavailable');
        $this->postJson('/api/v1/catalog/games/BAD..rom/scrape', [], serviceHeaders($service, $token))->assertNotFound();
        $this->postJson('/api/v1/catalog/games/tetris/scrape', [], cabinetHeaders($client, $cabinetToken))->assertForbidden();
    });
});
