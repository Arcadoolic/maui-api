<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\StartupsRelationManager;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** A startup received at 13:08:03 UTC. */
function startupAt1308Utc(): Client
{
    $client = Client::factory()->create();
    $client->startups()->create([
        'mame_version' => '0.289', 'maui_version' => '2.5.0', 'os' => 'linux', 'os_version' => '6.8.0',
        'client_datetime' => Carbon::parse('2026-09-25 13:08:03', 'UTC'),
        'received_at' => Carbon::parse('2026-09-25 13:08:03', 'UTC'),
    ]);

    return $client;
}

it('gives new admins the Paris timezone', function () {
    // Like `make:filament-user`, which knows nothing about the timezone.
    $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret']);

    expect($admin->fresh()->timezone)->toBe('Europe/Paris');
});

it('shows dates in the timezone of the logged-in admin', function (string $timezone, string $shown) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['timezone' => $timezone]));
    $client = startupAt1308Utc();

    livewire(StartupsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSee($shown)
        ->assertDontSee('13:08:03');
})->with([
    'Paris (UTC+2 in September)' => ['Europe/Paris', '15:08:03'],
    'New York (UTC-4 in September)' => ['America/New_York', '09:08:03'],
]);

it('stores dates in UTC whatever the admin timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Tokyo']));

    $startup = startupAt1308Utc()->startups()->first();

    expect($startup->getRawOriginal('received_at'))->toStartWith('2026-09-25 13:08:03');
});

it('falls back to Paris when nobody is logged in', function () {
    expect(FilamentTimezone::get())->toBe('Europe/Paris');
});

it('lets an admin choose their timezone on the profile page', function () {
    $admin = User::factory()->withAppAuthentication()->create();
    $this->actingAs($admin);

    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'America/New_York'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->fresh()->timezone)->toBe('America/New_York');
});

it('refuses an unknown timezone', function () {
    $this->actingAs(User::factory()->withAppAuthentication()->create());

    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'Mars/Olympus_Mons'])
        ->call('save')
        ->assertHasFormErrors(['timezone']);
});
