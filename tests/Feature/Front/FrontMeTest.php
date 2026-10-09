<?php

use App\Models\Client;
use App\Models\Member;
use App\Models\Player;
use Illuminate\Support\Facades\Auth;

// The logged-in member and its players, linked with initials + PIN (docs/DECISIONS.md D66).

beforeEach(function () {
    config(['front.url' => 'https://hiscores.test']);
});

/** What a browser sends with a request that changes something. */
const FRONT_ORIGIN = ['Origin' => 'https://hiscores.test'];

describe('session', function () {
    it('refuses a visitor', function () {
        $this->getJson('/api/v1/front/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });

    it('shows the member and its players', function () {
        $member = Member::factory()->create([
            'discord_id' => '42', 'username' => 'blinky', 'display_name' => 'Blinky', 'discord_avatar' => 'abc',
        ]);
        $player = Player::factory()->create(['pseudo_3' => 'ACE', 'is_public' => true]);
        $member->players()->attach($player, ['linked_at' => now()]);

        $this->actingAs($member, 'member')->getJson('/api/v1/front/me')
            ->assertOk()
            ->assertExactJson([
                'member' => [
                    'id' => $member->uuid,
                    'username' => 'blinky',
                    'display_name' => 'Blinky',
                    'avatar' => 'https://cdn.discordapp.com/avatars/42/abc.png',
                ],
                'players' => [[
                    'id' => $player->uuid,
                    'pseudo_3' => 'ACE',
                    'is_public' => true,
                    'status' => 'active',
                    'avatar' => null,
                ]],
            ]);
    });

    it('logs a member disabled since its login out', function () {
        $member = Member::factory()->create();
        $this->actingAs($member, 'member');
        $member->disable();

        $this->getJson('/api/v1/front/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'member_disabled');
    });

    it('logs out', function () {
        $this->actingAs(Member::factory()->create(), 'member')
            ->postJson('/api/v1/front/logout', [], FRONT_ORIGIN)
            ->assertNoContent();

        expect(Auth::guard('member')->check())->toBeFalse();
    });

    it('refuses a change that does not come from the front', function (array $headers) {
        $this->actingAs(Member::factory()->create(), 'member')
            ->postJson('/api/v1/front/logout', [], $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'origin_not_allowed');

        expect(Auth::guard('member')->check())->toBeTrue();
    })->with([
        'no origin' => [[]],
        'another site' => [['Origin' => 'https://evil.test']],
    ]);
});

describe('linking a player', function () {
    it('links a player with its initials and PIN', function () {
        $member = Member::factory()->create();
        $player = Player::factory()->create(['pseudo_3' => 'ACE', 'pin' => '4321']);

        $this->actingAs($member, 'member')
            ->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '4321'], FRONT_ORIGIN)
            ->assertOk()
            ->assertJsonPath('player.id', $player->uuid)
            ->assertJsonPath('player.pseudo_3', 'ACE');

        expect($member->players()->pluck('players.id')->all())->toBe([$player->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'player.member_linked', 'subject_id' => $player->id]);
    });

    it('counts a wrong PIN and locks the player after too many, as on a cabinet', function () {
        $member = Member::factory()->create();
        $player = Player::factory()->create(['pseudo_3' => 'ACE', 'pin' => '4321']);
        $this->actingAs($member, 'member');

        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '0000'], FRONT_ORIGIN)
            ->assertForbidden()
            ->assertJsonPath('code', 'pin_invalid')
            ->assertJsonPath('attempts_left', Player::MAX_PIN_ATTEMPTS - 1);

        $player->forceFill(['pin_failed_attempts' => Player::MAX_PIN_ATTEMPTS - 1])->save();
        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '0000'], FRONT_ORIGIN)
            ->assertStatus(423)
            ->assertJsonPath('code', 'player_locked');

        // Locked: the right PIN does not work any more.
        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '4321'], FRONT_ORIGIN)
            ->assertStatus(423);
        expect($member->players()->count())->toBe(0);
    });

    it('keeps the cabinets of the player as they are', function () {
        $cabinet = Client::factory()->create();
        $player = linkedPlayer($cabinet, ['pseudo_3' => 'ACE']);

        $this->actingAs(Member::factory()->create(), 'member')
            ->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '1234'], FRONT_ORIGIN)
            ->assertOk();

        expect($player->clients()->pluck('clients.id')->all())->toBe([$cabinet->id]);
    });

    it('refuses unknown initials and a disabled player', function () {
        Player::factory()->disabled()->create(['pseudo_3' => 'BAD']);
        $this->actingAs(Member::factory()->create(), 'member');

        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ZZZ', 'pin' => '1234'], FRONT_ORIGIN)
            ->assertNotFound()->assertJsonPath('code', 'player_not_found');
        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'BAD', 'pin' => '1234'], FRONT_ORIGIN)
            ->assertForbidden()->assertJsonPath('code', 'player_disabled');
    });

    it('refuses a player another member has linked', function () {
        $player = Player::factory()->create(['pseudo_3' => 'ACE']);
        Member::factory()->create()->players()->attach($player, ['linked_at' => now()]);

        $this->actingAs(Member::factory()->create(), 'member')
            ->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '1234'], FRONT_ORIGIN)
            ->assertConflict()
            ->assertJsonPath('code', 'player_already_linked');
    });

    it('links the same player twice without an error', function () {
        $member = Member::factory()->create();
        Player::factory()->create(['pseudo_3' => 'ACE']);
        $this->actingAs($member, 'member');

        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '1234'], FRONT_ORIGIN)->assertOk();
        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '1234'], FRONT_ORIGIN)->assertOk();

        expect($member->players()->count())->toBe(1);
    });

    it('links several players to one member', function () {
        $member = Member::factory()->create();
        Player::factory()->create(['pseudo_3' => 'ACE']);
        Player::factory()->create(['pseudo_3' => 'BOB']);
        $this->actingAs($member, 'member');

        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ACE', 'pin' => '1234'], FRONT_ORIGIN)->assertOk();
        $this->postJson('/api/v1/front/me/players', ['pseudo_3' => 'BOB', 'pin' => '1234'], FRONT_ORIGIN)->assertOk();

        $this->getJson('/api/v1/front/me')->assertJsonPath('players.*.pseudo_3', ['ACE', 'BOB']);
    });

    it('validates the initials and the PIN', function () {
        $this->actingAs(Member::factory()->create(), 'member')
            ->postJson('/api/v1/front/me/players', ['pseudo_3' => 'ab', 'pin' => '12'], FRONT_ORIGIN)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['pseudo_3', 'pin']]);
    });
});

describe('unlinking a player', function () {
    it('unlinks a player of the member', function () {
        $member = Member::factory()->create();
        $player = Player::factory()->create();
        $member->players()->attach($player, ['linked_at' => now()]);

        $this->actingAs($member, 'member')
            ->deleteJson('/api/v1/front/me/players/'.$player->uuid, [], FRONT_ORIGIN)
            ->assertNoContent();

        expect($member->players()->count())->toBe(0);
        $this->assertDatabaseHas('activity_log', ['event' => 'player.member_unlinked', 'subject_id' => $player->id]);
    });

    it('does not know the players of another member', function () {
        $player = Player::factory()->create();
        $other = Member::factory()->create();
        $other->players()->attach($player, ['linked_at' => now()]);

        $this->actingAs(Member::factory()->create(), 'member')
            ->deleteJson('/api/v1/front/me/players/'.$player->uuid, [], FRONT_ORIGIN)
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');

        expect($other->players()->count())->toBe(1);
    });
});
