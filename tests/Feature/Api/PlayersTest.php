<?php

use App\Models\Client;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

// Global players, linked to cabinets with a PIN (docs/DECISIONS.md D48).

/**
 * Creates a player linked to a cabinet, with a known PIN.
 *
 * @param  array<string, mixed>  $attributes
 */
function linkedPlayer(Client $client, array $attributes = [], string $pin = '1234'): Player
{
    $player = Player::factory()->create([...$attributes, 'pin' => $pin]);
    $player->clients()->attach($client, ['linked_at' => now()]);

    return $player;
}

describe('availability', function () {
    it('tells free, taken and disabled initials apart', function () {
        [$client, $token] = cabinetWithToken();
        Player::factory()->create(['pseudo_3' => 'ACE']);
        Player::factory()->disabled()->create(['pseudo_3' => 'BAD']);

        foreach (['ZZZ' => 'free', 'ACE' => 'taken', 'BAD' => 'disabled'] as $pseudo3 => $availability) {
            $this->getJson("/api/v1/players/availability?pseudo_3={$pseudo3}", cabinetHeaders($client, $token))
                ->assertOk()
                ->assertExactJson(['pseudo_3' => $pseudo3, 'availability' => $availability]);
        }
    });

    it('validates the initials', function (string $pseudo3) {
        [$client, $token] = cabinetWithToken();

        $this->getJson("/api/v1/players/availability?pseudo_3={$pseudo3}", cabinetHeaders($client, $token))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['pseudo_3']]);
    })->with(['AB', 'ABCD', 'ab1', 'abc']);
});

describe('creation', function () {
    it('creates a player linked to the cabinet and shows the PIN once', function () {
        [$client, $token] = cabinetWithToken();

        $response = $this->postJson('/api/v1/players', ['pseudo_3' => 'ACE', 'is_public' => true], cabinetHeaders($client, $token))
            ->assertCreated()
            ->assertJsonPath('player.pseudo_3', 'ACE')
            ->assertJsonPath('player.is_public', true)
            ->assertJsonPath('player.status', 'active');

        $pin = $response->json('pin');
        expect($pin)->toMatch('/^\d{4}$/');

        $player = Player::query()->where('pseudo_3', 'ACE')->firstOrFail();
        expect($response->json('player.id'))->toBe($player->uuid)
            ->and($player->pin)->toBe($pin)
            ->and($player->clients()->whereKey($client->id)->exists())->toBeTrue();
    });

    it('creates a private player by default', function () {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/players', ['pseudo_3' => 'ACE'], cabinetHeaders($client, $token))
            ->assertCreated()
            ->assertJsonPath('player.is_public', false);
    });

    it('refuses initials already taken', function () {
        [$client, $token] = cabinetWithToken();
        Player::factory()->create(['pseudo_3' => 'ACE']);

        $this->postJson('/api/v1/players', ['pseudo_3' => 'ACE'], cabinetHeaders($client, $token))
            ->assertConflict()
            ->assertJsonPath('code', 'initials_taken');

        expect(Player::query()->count())->toBe(1);
    });

    it('validates the initials', function () {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/players', ['pseudo_3' => 'ac3'], cabinetHeaders($client, $token))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['pseudo_3']]);
    });
});

describe('link with a PIN', function () {
    it('links an existing player to another cabinet', function () {
        [$home] = cabinetWithToken();
        $player = linkedPlayer($home, ['pseudo_3' => 'ACE']);
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '1234'], cabinetHeaders($client, $token, fingerprint('cabinet-b')))
            ->assertOk()
            ->assertJsonPath('player.id', $player->uuid)
            ->assertJsonMissingPath('pin');

        expect($player->clients()->pluck('clients.id')->sort()->values()->all())->toBe([$home->id, $client->id]);
    });

    it('is idempotent for a player already linked', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client, ['pseudo_3' => 'ACE']);

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '1234'], cabinetHeaders($client, $token))
            ->assertOk();

        expect($player->clients()->count())->toBe(1);
    });

    it('counts wrong PINs and locks the player after five', function () {
        [$home] = cabinetWithToken();
        $player = linkedPlayer($home, ['pseudo_3' => 'ACE']);
        [$client, $token] = cabinetWithToken();
        $headers = cabinetHeaders($client, $token);

        foreach ([4, 3, 2, 1] as $left) {
            $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '0000'], $headers)
                ->assertForbidden()
                ->assertJsonPath('code', 'pin_invalid')
                ->assertJsonPath('attempts_left', $left);
        }

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '0000'], $headers)
            ->assertStatus(423)
            ->assertJsonPath('code', 'player_locked');

        // Locked: even the right PIN is refused.
        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '1234'], $headers)
            ->assertStatus(423)
            ->assertJsonPath('code', 'player_locked');

        expect($player->fresh()->isLocked())->toBeTrue()
            ->and($player->clients()->count())->toBe(1);
    });

    it('resets the failure count after the right PIN', function () {
        [$home] = cabinetWithToken();
        $player = linkedPlayer($home, ['pseudo_3' => 'ACE']);
        [$client, $token] = cabinetWithToken();
        $headers = cabinetHeaders($client, $token);

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '0000'], $headers)->assertForbidden();
        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '1234'], $headers)->assertOk();

        expect($player->fresh()->pin_failed_attempts)->toBe(0);
    });

    it('refuses unknown and disabled players', function () {
        [$client, $token] = cabinetWithToken();
        Player::factory()->disabled()->create(['pseudo_3' => 'BAD', 'pin' => '1234']);

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ZZZ', 'pin' => '1234'], cabinetHeaders($client, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'BAD', 'pin' => '1234'], cabinetHeaders($client, $token))
            ->assertForbidden()
            ->assertJsonPath('code', 'player_disabled');
    });

    it('validates the PIN format', function () {
        [$client, $token] = cabinetWithToken();

        $this->postJson('/api/v1/players/link', ['pseudo_3' => 'ACE', 'pin' => '12a4'], cabinetHeaders($client, $token))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['pin']]);
    });
});

describe('players of the cabinet', function () {
    it('lists the players linked to this cabinet only', function () {
        [$client, $token] = cabinetWithToken();
        $mine = linkedPlayer($client, ['pseudo_3' => 'ACE', 'is_public' => true]);
        $locked = linkedPlayer($client, ['pseudo_3' => 'LCK', 'pin_locked_at' => now()]);
        $disabled = linkedPlayer($client, ['pseudo_3' => 'BAD']);
        $disabled->disable();
        [$other] = cabinetWithToken();
        linkedPlayer($other, ['pseudo_3' => 'OTH']);

        $this->getJson('/api/v1/players', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertExactJson(['players' => [
                ['id' => $mine->uuid, 'pseudo_3' => 'ACE', 'is_public' => true, 'status' => 'active'],
                ['id' => $disabled->uuid, 'pseudo_3' => 'BAD', 'is_public' => false, 'status' => 'disabled'],
                ['id' => $locked->uuid, 'pseudo_3' => 'LCK', 'is_public' => false, 'status' => 'locked'],
            ]]);
    });

    it('changes the visibility of a linked player', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);

        $this->patchJson("/api/v1/players/{$player->uuid}", ['is_public' => true], cabinetHeaders($client, $token))
            ->assertOk()
            ->assertJsonPath('player.is_public', true);

        expect($player->fresh()->is_public)->toBeTrue();
    });

    it('regenerates the PIN, which also unlocks the player', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client, ['pin_locked_at' => now(), 'pin_failed_attempts' => 5]);

        $pin = $this->postJson("/api/v1/players/{$player->uuid}/pin", [], cabinetHeaders($client, $token))
            ->assertOk()
            ->json('pin');

        $player->refresh();
        expect($pin)->toMatch('/^\d{4}$/')
            ->and($player->pin)->toBe($pin)
            ->and($player->isLocked())->toBeFalse()
            ->and($player->pin_failed_attempts)->toBe(0);
    });

    it('unlinks a player from this cabinet only', function () {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        [$other] = cabinetWithToken();
        $player->clients()->attach($other, ['linked_at' => now()]);

        $this->deleteJson("/api/v1/players/{$player->uuid}/link", [], cabinetHeaders($client, $token))
            ->assertNoContent();

        expect($player->clients()->pluck('clients.id')->all())->toBe([$other->id])
            ->and(Player::query()->count())->toBe(1);
    });

    it('hides the players of other cabinets', function (string $method, string $suffix) {
        [$client, $token] = cabinetWithToken();
        [$other] = cabinetWithToken();
        $player = linkedPlayer($other);

        $this->json($method, "/api/v1/players/{$player->uuid}{$suffix}", ['is_public' => true], cabinetHeaders($client, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    })->with([
        'visibility' => ['PATCH', ''],
        'new PIN' => ['POST', '/pin'],
        'unlink' => ['DELETE', '/link'],
    ]);

    it('refuses changes to a disabled player', function (string $method, string $suffix) {
        [$client, $token] = cabinetWithToken();
        $player = linkedPlayer($client);
        $player->disable();

        $this->json($method, "/api/v1/players/{$player->uuid}{$suffix}", ['is_public' => true], cabinetHeaders($client, $token))
            ->assertForbidden()
            ->assertJsonPath('code', 'player_disabled');
    })->with([
        'visibility' => ['PATCH', ''],
        'new PIN' => ['POST', '/pin'],
    ]);

    it('answers 404 for a malformed player id', function () {
        [$client, $token] = cabinetWithToken();

        $this->patchJson('/api/v1/players/not-a-uuid', ['is_public' => true], cabinetHeaders($client, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    });
});

it('stores the PIN encrypted, never in clear', function () {
    [$client, $token] = cabinetWithToken();

    $pin = $this->postJson('/api/v1/players', ['pseudo_3' => 'ACE'], cabinetHeaders($client, $token))->json('pin');

    $stored = DB::table('players')->where('pseudo_3', 'ACE')->value('pin');
    expect($stored)->not->toContain($pin)
        ->and(decrypt($stored, unserialize: false))->toBe($pin);
});

it('requires the players ability', function () {
    [$client, $token] = serviceWithToken();

    $this->getJson('/api/v1/players', serviceHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

it('grants players to the cabinet tokens issued before the ability existed', function () {
    [$cabinet] = cabinetWithToken();
    [$service] = serviceWithToken();
    $before = ['session', 'scores:write', 'scores:read', 'repository:read'];
    DB::table('personal_access_tokens')->where('tokenable_id', $cabinet->id)
        ->update(['abilities' => json_encode($before)]);
    DB::table('personal_access_tokens')->where('tokenable_id', $service->id)
        ->update(['abilities' => json_encode(['catalog:write', 'repository:read'])]);

    $migration = require database_path('migrations/2026_10_02_100001_grant_players_to_cabinet_tokens.php');
    $migration->up();
    $migration->up(); // idempotent

    expect($cabinet->tokens()->first()->abilities)->toBe([...$before, 'players'])
        ->and($service->tokens()->first()->abilities)->toBe(['catalog:write', 'repository:read']);

    $migration->down();

    expect($cabinet->tokens()->first()->abilities)->toBe($before);
});
