<?php

use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Services\Players\PlayerAvatars;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The hiscores app of a TV: the global podium, the score events and the
// players linked to it with their PIN (docs/DECISIONS.md D102).

/** A player made on a cabinet, with PIN 1234. */
function tvPlayer(string $pseudo3, bool $public = true): Player
{
    return linkedPlayer(Client::factory()->create(), ['pseudo_3' => $pseudo3, 'is_public' => $public]);
}

function tvLink(Client $tv, Player $player): void
{
    $tv->tvPlayers()->attach($player, ['linked_at' => now()]);
}

/** Gives the player an avatar, from its cabinet: its hash. */
function tvAvatar(Player $player): string
{
    $origin = Client::query()->findOrFail($player->origin_client_id);
    app(PlayerAvatars::class)->store($origin, $player, UploadedFile::fake()->createWithContent('a.png', pngBytes(64)));

    return (string) $player->refresh()->avatar_hash;
}

describe('global podium and events', function () {
    it('answers the global podium with the avatars', function () {
        $game = Game::factory()->create();
        leaderboardScore($game, publicPlayer('ACE'), 900);
        leaderboardScore($game, publicPlayer('BOB'), 800);

        $response = $this->getJson('/api/v1/tv/ranking', serviceHeaders(...tvWithToken()))->assertOk();

        expect($response->json('ranking.*.player.pseudo_3'))->toBe(['ACE', 'BOB'])
            ->and($response->json('ranking.0.player'))->toHaveKey('avatar');
    });

    it('answers the score events', function () {
        $this->getJson('/api/v1/tv/events', serviceHeaders(...tvWithToken()))
            ->assertOk()
            ->assertJsonStructure(['events', 'cursor']);
    });

    it('needs a TV account', function (string $method, string $uri, Closure $headers) {
        $this->json($method, $uri, [], $headers())
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    })->with([
        ['GET', '/api/v1/tv/ranking'],
        ['GET', '/api/v1/tv/events'],
        ['GET', '/api/v1/tv/players'],
        ['POST', '/api/v1/tv/players/link'],
    ])->with([
        'bot' => [fn () => serviceHeaders(...botWithToken())],
        'catalog service account' => [fn () => serviceHeaders(...serviceWithToken())],
        'cabinet' => [fn () => cabinetHeaders(...cabinetWithToken())],
    ]);

    it('refuses a request without credentials', function () {
        $this->getJson('/api/v1/tv/ranking')->assertUnauthorized();
    });
});

describe('linking players', function () {
    it('links a player with its initials and PIN, private ones included', function () {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE', public: false);

        $this->postJson('/api/v1/tv/players/link', ['pseudo_3' => 'ACE', 'pin' => '1234'], serviceHeaders($tv, $token))
            ->assertOk()
            ->assertJsonPath('player.id', $player->uuid)
            ->assertJsonPath('player.pseudo_3', 'ACE');

        expect($tv->tvPlayers()->pluck('players.id')->all())->toBe([$player->id])
            // Not a cabinet of the player: its cabinets are unchanged.
            ->and($player->clients()->count())->toBe(1);
    });

    it('is idempotent for a player already linked', function () {
        [$tv, $token] = tvWithToken();
        tvPlayer('ACE');

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/tv/players/link', ['pseudo_3' => 'ACE', 'pin' => '1234'], serviceHeaders($tv, $token))->assertOk();
        }

        expect($tv->tvPlayers()->count())->toBe(1);
    });

    it('counts wrong PINs with the cabinets and locks the player after five', function () {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE');

        foreach ([4, 3, 2, 1] as $left) {
            $this->postJson('/api/v1/tv/players/link', ['pseudo_3' => 'ACE', 'pin' => '0000'], serviceHeaders($tv, $token))
                ->assertForbidden()
                ->assertJsonPath('code', 'pin_invalid')
                ->assertJsonPath('attempts_left', $left);
        }
        $this->postJson('/api/v1/tv/players/link', ['pseudo_3' => 'ACE', 'pin' => '0000'], serviceHeaders($tv, $token))
            ->assertStatus(423)
            ->assertJsonPath('code', 'player_locked');

        expect($player->fresh()->isLocked())->toBeTrue()
            ->and($tv->tvPlayers()->count())->toBe(0);
    });

    it('refuses unknown players and validates the PIN', function () {
        [$tv, $token] = tvWithToken();

        $this->postJson('/api/v1/tv/players/link', ['pseudo_3' => 'ZZZ', 'pin' => '1234'], serviceHeaders($tv, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
        $this->postJson('/api/v1/tv/players/link', ['pseudo_3' => 'ZZZ', 'pin' => '12'], serviceHeaders($tv, $token))
            ->assertUnprocessable();
    });

    it('lists the players linked to this TV only, with their place on the podium', function () {
        [$tv, $token] = tvWithToken();
        [$other] = tvWithToken();
        $ace = tvPlayer('ACE');
        $bob = tvPlayer('BOB');
        tvLink($tv, $ace);
        tvLink($other, $bob);
        leaderboardScore(Game::factory()->create(), $ace, 500);

        $response = $this->getJson('/api/v1/tv/players', serviceHeaders($tv, $token))->assertOk();

        expect($response->json('players'))->toHaveCount(1)
            ->and($response->json('players.0.player.pseudo_3'))->toBe('ACE')
            ->and($response->json('players.0.stats.global_rank'))->toBe(1)
            ->and($response->json('players.0.stats.crowns'))->toBe(1);
    });

    it('unlinks a player, which stays on its cabinets', function () {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE');
        tvLink($tv, $player);

        $this->deleteJson("/api/v1/tv/players/{$player->uuid}/link", [], serviceHeaders($tv, $token))->assertNoContent();

        expect($tv->tvPlayers()->count())->toBe(0)
            ->and($player->clients()->count())->toBe(1);
    });
});

describe('player page', function () {
    it('shows a linked player as the front does, private ones included', function () {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE', public: false);
        tvLink($tv, $player);
        leaderboardScore(Game::factory()->create(['description' => 'Galaga']), $player, 500);

        $this->getJson("/api/v1/tv/players/{$player->uuid}", serviceHeaders($tv, $token))
            ->assertOk()
            ->assertJsonPath('player.pseudo_3', 'ACE')
            ->assertJsonPath('player.is_mine', true)
            ->assertJsonPath('bests.0.game.description', 'Galaga')
            ->assertJsonPath('bests.0.score', 500);
    });

    it('does not show a player not linked to this TV', function () {
        [$tv, $token] = tvWithToken();
        [$other] = tvWithToken();
        $player = tvPlayer('ACE');
        tvLink($other, $player);

        $this->getJson("/api/v1/tv/players/{$player->uuid}", serviceHeaders($tv, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
        $this->deleteJson("/api/v1/tv/players/{$player->uuid}/link", [], serviceHeaders($tv, $token))
            ->assertNotFound();
    });
});

describe('avatars', function () {
    beforeEach(fn () => Storage::fake('local'));

    it('serves the avatars of public players and of its own players, with an ETag', function (bool $public, bool $linked) {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE', public: $public);
        if ($linked) {
            tvLink($tv, $player);
        }
        $hash = tvAvatar($player);

        $this->get("/api/v1/tv/players/{$player->uuid}/avatar", serviceHeaders($tv, $token))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('ETag', '"'.$hash.'"');
    })->with([
        'public' => [true, false],
        'private, linked' => [false, true],
    ]);

    it('hides the avatar of a private player of another TV', function () {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE', public: false);
        tvAvatar($player);

        $this->getJson("/api/v1/tv/players/{$player->uuid}/avatar", serviceHeaders($tv, $token))->assertNotFound();
    });
});

describe('games a player is not ranked on', function () {
    it('lists the games with scores where the player is not ranked, with their leader', function () {
        [$tv, $token] = tvWithToken();
        $ace = tvPlayer('ACE');
        tvLink($tv, $ace);
        $both = Game::factory()->create(['romname' => 'both', 'description' => 'Both']);
        $theirs = Game::factory()->create(['romname' => 'theirs', 'description' => 'Theirs (World)']);
        Game::factory()->create(['romname' => 'empty']);
        leaderboardScore($both, $ace, 100);
        leaderboardScore($both, publicPlayer('BOB'), 200);
        leaderboardScore($theirs, publicPlayer('CAT'), 300);
        $cat = Player::query()->where('pseudo_3', 'CAT')->firstOrFail();

        $this->getJson("/api/v1/tv/players/{$ace->uuid}/unranked", serviceHeaders($tv, $token))
            ->assertOk()
            ->assertExactJson([
                'games' => [[
                    'romname' => 'theirs',
                    'description' => 'Theirs (World)',
                    'leader' => ['player' => ['id' => $cat->uuid, 'pseudo_3' => 'CAT', 'avatar' => null], 'score' => 300],
                ]],
                'total' => 1,
            ]);
    });

    it('answers fewer games on demand, with the total', function () {
        [$tv, $token] = tvWithToken();
        $ace = tvPlayer('ACE');
        tvLink($tv, $ace);
        foreach (['aaa', 'bbb', 'ccc'] as $romname) {
            leaderboardScore(Game::factory()->create(['romname' => $romname]), publicPlayer(strtoupper($romname)), 100);
        }

        $this->getJson("/api/v1/tv/players/{$ace->uuid}/unranked?limit=2", serviceHeaders($tv, $token))
            ->assertOk()
            ->assertJsonCount(2, 'games')
            ->assertJsonPath('total', 3);
        $this->getJson("/api/v1/tv/players/{$ace->uuid}/unranked?limit=31", serviceHeaders($tv, $token))->assertUnprocessable();
    });

    it('is only for the players of this TV', function () {
        [$tv, $token] = tvWithToken();
        $player = tvPlayer('ACE');

        $this->getJson("/api/v1/tv/players/{$player->uuid}/unranked", serviceHeaders($tv, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    });
});
