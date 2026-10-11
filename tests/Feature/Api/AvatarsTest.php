<?php

use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Services\Players\PlayerAvatars;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Player avatars (docs/DECISIONS.md D53).

beforeEach(fn () => Storage::fake('local'));

function avatarPng(int $size = 64): UploadedFile
{
    return UploadedFile::fake()->createWithContent('NOB.png', pngBytes($size));
}

describe('upload', function () {
    it('stores the PNG of a player of the cabinet and records its hash', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $png = avatarPng();

        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => $png], [...cabinetHeaders($client, $token), 'Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('avatar', hash('sha256', (string) $png->get()));

        Storage::disk('local')->assertExists("avatars/{$player->uuid}.png");
        expect($player->refresh()->avatar_hash)->toBe(hash('sha256', (string) $png->get()));
    });

    it('refuses what is not a PNG, too big or too large', function (Closure $file) {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => $file()], [...cabinetHeaders($client, $token), 'Accept' => 'application/json'])
            ->assertUnprocessable();
        expect($player->refresh()->avatar_hash)->toBeNull();
    })->with([
        'jpeg' => [fn () => UploadedFile::fake()->createWithContent('NOB.png', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat("\x00", 64))],
        'text named png' => [fn () => UploadedFile::fake()->createWithContent('NOB.png', 'not an image')],
        'over 256 KB' => [fn () => UploadedFile::fake()->createWithContent('NOB.png', pngBytes(64).random_bytes(300 * 1024))],
        'over 1024 px' => [fn () => avatarPng(1100)],
    ]);

    it('refuses a cabinet the player was only linked to (D56)', function () {
        [$origin] = cabinetWithToken();
        [$other, $otherToken] = cabinetWithToken();
        $player = linkedPlayer($origin);
        $player->clients()->attach($other, ['linked_at' => now()]);

        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng()], [...cabinetHeaders($other, $otherToken), 'Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_origin_cabinet');
        expect($player->refresh()->avatar_hash)->toBeNull();
        Storage::disk('local')->assertMissing("avatars/{$player->uuid}.png");
    });

    it('refuses a player of another cabinet', function () {
        [$client, $token] = cabinetWithToken();
        $stranger = Player::factory()->create();

        $this->post("/api/v1/players/{$stranger->uuid}/avatar", ['avatar' => avatarPng()], [...cabinetHeaders($client, $token), 'Accept' => 'application/json'])
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    });
});

describe('download', function () {
    it('serves the PNG of a public player to any cabinet, 304 when unchanged', function () {
        [$owner, $ownerToken] = cabinetWithToken();
        [$other, $otherToken] = cabinetWithToken();
        $player = linkedPlayer($owner, ['is_public' => true]);
        $png = avatarPng();
        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => $png], [...cabinetHeaders($owner, $ownerToken), 'Accept' => 'application/json'])->assertOk();

        $response = $this->get("/api/v1/players/{$player->uuid}/avatar", cabinetHeaders($other, $otherToken))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        expect($response->headers->get('ETag'))->toBe('"'.hash('sha256', (string) $png->get()).'"');

        $this->get("/api/v1/players/{$player->uuid}/avatar", [...cabinetHeaders($other, $otherToken), 'If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    });

    it('answers 404 without an avatar, or for a private player', function () {
        [$client, $token] = cabinetWithToken();
        $none = Player::factory()->create(['is_public' => true]);
        $private = Player::factory()->create(['is_public' => false, 'avatar_hash' => str_repeat('a', 64)]);

        $this->getJson("/api/v1/players/{$none->uuid}/avatar", cabinetHeaders($client, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'avatar_not_found');
        $this->getJson("/api/v1/players/{$private->uuid}/avatar", cabinetHeaders($client, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    });

    it('serves a private player to the cabinets it is linked to only (D56)', function () {
        [$origin, $originToken] = cabinetWithToken();
        [$linked, $linkedToken] = cabinetWithToken();
        [$stranger, $strangerToken] = cabinetWithToken();
        $player = linkedPlayer($origin, ['is_public' => false]);
        $player->clients()->attach($linked, ['linked_at' => now()]);
        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng()], [...cabinetHeaders($origin, $originToken), 'Accept' => 'application/json'])->assertOk();

        $this->get("/api/v1/players/{$player->uuid}/avatar", cabinetHeaders($linked, $linkedToken))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->getJson("/api/v1/players/{$player->uuid}/avatar", cabinetHeaders($stranger, $strangerToken))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    });

    it('gives the avatar hash in the leaderboards', function () {
        [$client, $token] = cabinetWithToken();
        $player = Player::factory()->create(['is_public' => true, 'avatar_hash' => str_repeat('b', 64)]);
        Score::factory()->create(['player_id' => $player->id, 'game_id' => Game::factory()->create(['romname' => 'dkong'])->id]);

        $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))
            ->assertJsonPath('entries.0.player.avatar', str_repeat('b', 64));
    });
});

// An administrator may change an avatar too (docs/DECISIONS.md D83).
describe('avatar changed by an administrator', function () {
    it('tells the cabinets whose picture comes from an administrator', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $headers = [...cabinetHeaders($client, $token), 'Accept' => 'application/json'];

        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng()], $headers)
            ->assertOk()
            ->assertJsonPath('player.avatar_by_admin', false);

        app(PlayerAvatars::class)->storeByAdmin($player, pngBytes(256));

        $this->getJson('/api/v1/players', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertJsonPath('players.0.avatar', hash('sha256', pngBytes(256)))
            ->assertJsonPath('players.0.avatar_by_admin', true);
    });

    it('refuses the picture of a cabinet that has not seen the administrator\'s one', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $headers = [...cabinetHeaders($client, $token), 'Accept' => 'application/json'];
        app(PlayerAvatars::class)->storeByAdmin($player, pngBytes(256));
        $admins = hash('sha256', pngBytes(256));

        // As a cabinet older than this decision sends it: nothing about what it replaces.
        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng()], $headers)
            ->assertConflict()
            ->assertJsonPath('code', 'avatar_changed_by_admin');
        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng(), 'replaces' => str_repeat('a', 64)], $headers)
            ->assertConflict();
        expect($player->refresh()->avatar_hash)->toBe($admins)
            ->and($player->avatar_by_admin)->toBeTrue();
    });

    it('takes the picture of the cabinet that replaces the administrator\'s one knowingly', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $headers = [...cabinetHeaders($client, $token), 'Accept' => 'application/json'];
        app(PlayerAvatars::class)->storeByAdmin($player, pngBytes(256));

        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng(), 'replaces' => hash('sha256', pngBytes(256))], $headers)
            ->assertOk()
            ->assertJsonPath('avatar', hash('sha256', pngBytes(64)))
            ->assertJsonPath('player.avatar_by_admin', false);
        expect($player->refresh()->avatar_by_admin)->toBeFalse();
    });

    it('validates what a cabinet says it replaces', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        $this->post("/api/v1/players/{$player->uuid}/avatar", ['avatar' => avatarPng(), 'replaces' => 'nope'], [...cabinetHeaders($client, $token), 'Accept' => 'application/json'])
            ->assertUnprocessable();
    });

    it('refuses from an administrator what is no picture', function () {
        $player = Player::factory()->create();

        expect(fn () => app(PlayerAvatars::class)->storeByAdmin($player, 'GIF89a not a picture'))->toThrow(ValidationException::class);
        expect($player->refresh()->avatar_hash)->toBeNull();
    });

    it('makes an administrator\'s picture fit, however large (D84)', function () {
        $player = Player::factory()->create();

        app(PlayerAvatars::class)->storeByAdmin($player, pngBytes(PlayerAvatars::MAX_DIMENSION + 1));

        $stored = (string) Storage::disk('local')->get("avatars/{$player->uuid}.png");
        expect(getimagesizefromstring($stored))->toMatchArray([0 => 512, 1 => 512, 2 => IMAGETYPE_PNG])
            ->and($player->refresh()->avatar_hash)->toBe(hash('sha256', $stored));
    });
});
