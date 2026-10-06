<?php

use App\Enums\PlayerStatus;
use App\Filament\Resources\Players\Pages\ListPlayers;
use App\Filament\Resources\Players\Pages\ViewPlayer;
use App\Filament\Resources\Players\PlayerResource;
use App\Filament\Resources\Players\RelationManagers\CabinetsRelationManager;
use App\Models\Client;
use App\Models\Player;
use App\Models\User;
use App\Services\Players\PlayerAdministration;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// Players in the back office (docs/DECISIONS.md D48).

beforeEach(function () {
    $this->admin = User::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin);
});

function playerAudit(Player $player, string $event): ?Activity
{
    return Activity::query()
        ->where('subject_type', $player->getMorphClass())
        ->where('subject_id', $player->getKey())
        ->where('event', $event)
        ->latest('id')
        ->first();
}

it('lists players', function () {
    $players = Player::factory()->count(3)->create();

    livewire(ListPlayers::class)->assertCanSeeTableRecords($players);
});

it('filters locked players', function () {
    $locked = Player::factory()->create(['pin_locked_at' => now()]);
    $free = Player::factory()->create();

    livewire(ListPlayers::class)
        ->filterTable('locked', true)
        ->assertCanSeeTableRecords([$locked])
        ->assertCanNotSeeTableRecords([$free]);
});

it('shows the cabinets of a player', function () {
    $player = Player::factory()->create();
    $client = Client::factory()->create();
    $player->clients()->attach($client, ['linked_at' => now()]);

    livewire(CabinetsRelationManager::class, ['ownerRecord' => $player, 'pageClass' => ViewPlayer::class])
        ->assertCanSeeTableRecords([$client]);
});

it('disables and enables a player, recorded with the admin', function () {
    $player = Player::factory()->create();

    livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])->callAction('disable');
    expect($player->fresh()->status)->toBe(PlayerStatus::Disabled)
        ->and(playerAudit($player, 'player.disabled')?->causer_id)->toBe($this->admin->id);

    livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])->callAction('enable');
    expect($player->fresh()->status)->toBe(PlayerStatus::Active)
        ->and(playerAudit($player, 'player.enabled'))->not->toBeNull();
});

it('unlocks a player without changing its PIN', function () {
    $player = Player::factory()->create(['pin_locked_at' => now(), 'pin_failed_attempts' => 5, 'pin' => '4321']);

    livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])->callAction('unlock');

    $player->refresh();
    expect($player->isLocked())->toBeFalse()
        ->and($player->pin_failed_attempts)->toBe(0)
        ->and($player->pin)->toBe('4321')
        ->and(playerAudit($player, 'player.unlocked'))->not->toBeNull();
});

it('shows the PIN to the admin, recorded without the PIN', function () {
    $player = Player::factory()->create(['pin' => '0042']);

    livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])
        ->callAction('showPin')
        ->assertActionMounted('showSecret')
        ->assertMountedActionModalSee('0042');

    $entry = playerAudit($player, 'player.pin_viewed');
    expect($entry?->causer_id)->toBe($this->admin->id)
        ->and(json_encode($entry?->properties))->not->toContain('0042');
});

it('issues a new PIN from the back office, which unlocks the player', function () {
    $player = Player::factory()->create(['pin' => '0042', 'pin_locked_at' => now(), 'pin_failed_attempts' => 5]);

    livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])
        ->callAction('regeneratePin')
        ->assertActionMounted('showSecret');

    $player->refresh();
    expect($player->pin)->toMatch('/^\d{4}$/')
        ->and($player->isLocked())->toBeFalse()
        ->and($player->pin_failed_attempts)->toBe(0)
        ->and(playerAudit($player, 'player.pin_regenerated')?->causer_id)->toBe($this->admin->id);
});

it('records the cabinet actions without the PIN', function () {
    [$client, $token] = cabinetWithToken();

    $pin = $this->postJson('/api/v1/players', ['pseudo_3' => 'ACE'], cabinetHeaders($client, $token))->json('pin');

    $entry = playerAudit(Player::query()->firstOrFail(), 'player.created');
    expect($entry?->causer_type)->toBe($client->getMorphClass())
        ->and($entry?->causer_id)->toBe($client->id)
        ->and(json_encode($entry?->properties))->not->toContain($pin);
});

it('cannot create, edit or delete players', function () {
    $player = Player::factory()->create();

    expect(PlayerResource::canCreate())->toBeFalse()
        ->and(PlayerResource::canEdit($player))->toBeFalse()
        ->and(PlayerResource::canDelete($player))->toBeFalse();
});

describe('origin cabinet (D54)', function () {
    it('lets an admin move the origin to another cabinet of the player', function () {
        $first = Client::factory()->create(['name' => 'first_cabinet']);
        $second = Client::factory()->create(['name' => 'second_cabinet']);
        $player = linkedPlayer($first);
        $player->clients()->attach($second, ['linked_at' => now()]);

        livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])
            ->callAction('setOrigin', ['origin_client_id' => $second->id])
            ->assertHasNoActionErrors();

        $audit = playerAudit($player, 'player.origin_changed');
        expect($player->refresh()->origin_client_id)->toBe($second->id)
            ->and($audit?->causer_id)->toBe($this->admin->id)
            ->and($audit?->properties->all())->toBe(['from' => 'first_cabinet', 'to' => 'second_cabinet']);
    });

    it('lets an admin leave a player without origin: admins only', function () {
        $player = linkedPlayer(Client::factory()->create());

        livewire(ViewPlayer::class, ['record' => $player->getRouteKey()])
            ->callAction('setOrigin', ['origin_client_id' => null]);

        expect($player->refresh()->origin_client_id)->toBeNull();
    });

    it('refuses a cabinet the player is not linked to', function () {
        $player = linkedPlayer(Client::factory()->create());
        $stranger = Client::factory()->create();

        expect(fn () => app(PlayerAdministration::class)->setOrigin($player, $stranger))
            ->toThrow(InvalidArgumentException::class);
        expect($player->refresh()->origin_client_id)->not->toBe($stranger->id);
    });

    it('makes the new origin the cabinet that can issue a PIN', function () {
        [$first, $firstToken] = cabinetWithToken();
        [$second, $secondToken] = cabinetWithToken();
        $player = linkedPlayer($first);
        $player->clients()->attach($second, ['linked_at' => now()]);

        app(PlayerAdministration::class)->setOrigin($player, $second);

        $this->postJson("/api/v1/players/{$player->uuid}/pin", [], cabinetHeaders($first, $firstToken))
            ->assertForbidden()
            ->assertJsonPath('code', 'not_origin_cabinet');
        $this->postJson("/api/v1/players/{$player->uuid}/pin", [], cabinetHeaders($second, $secondToken))->assertOk();
    });
});
