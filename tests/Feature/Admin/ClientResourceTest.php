<?php

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Enums\InvitationPurpose;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\StartupsRelationManager;
use App\Models\Client;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withAppAuthentication()->create());
});

it('lists clients', function () {
    $clients = Client::factory()->count(3)->create();

    livewire(ListClients::class)->assertCanSeeTableRecords($clients);
});

it('creates a cabinet with a generated name', function () {
    livewire(CreateClient::class)
        ->fillForm([
            'owner_name' => 'Jane Doe',
            'email' => 'owner@example.test',
            'type' => ClientType::Maui->value,
            'notes' => 'Garage',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::query()->where('email', 'owner@example.test')->firstOrFail();
    expect($client->name)->toMatch('/^[a-z]+(_[a-z0-9]+)+$/')
        ->and($client->owner_name)->toBe('Jane Doe')
        ->and($client->type)->toBe(ClientType::Maui)
        ->and($client->notes)->toBe('Garage');
});

it('creates a service account with the descriptive name given by the admin', function () {
    livewire(CreateClient::class)
        ->fillForm([
            'owner_name' => 'Catalog team',
            'email' => 'catalog@example.test',
            'type' => ClientType::Service->value,
            'name' => 'catalog_importer',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->where('name', 'catalog_importer')->first()?->type)->toBe(ClientType::Service);
});

it('requires a valid, free name for a service account', function (mixed $name, string $rule) {
    Client::factory()->create(['name' => 'catalog_importer']);

    livewire(CreateClient::class)
        ->fillForm([
            'owner_name' => 'Catalog team',
            'email' => 'catalog@example.test',
            'type' => ClientType::Service->value,
            'name' => $name,
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => $rule]);
})->with([
    'missing' => [null, 'required'],
    'not snake case' => ['Catalog Importer', 'regex'],
    'already used' => ['catalog_importer', 'unique'],
]);

it('only asks for a name when creating a service account', function () {
    livewire(CreateClient::class)
        ->fillForm(['type' => ClientType::Maui->value])
        ->assertFormFieldHidden('name')
        ->fillForm(['type' => ClientType::Service->value])
        ->assertFormFieldVisible('name');
});

it('lets one owner have several cabinets with the same email', function () {
    Client::factory()->create(['owner_name' => 'Jane Doe', 'email' => 'owner@example.test']);

    livewire(CreateClient::class)
        ->fillForm(['owner_name' => 'Jane Doe', 'email' => 'owner@example.test', 'type' => ClientType::Maui->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $cabinets = Client::query()->where('email', 'owner@example.test')->get();
    expect($cabinets)->toHaveCount(2)
        ->and($cabinets->pluck('public_key')->unique())->toHaveCount(2)
        ->and($cabinets->pluck('name')->unique())->toHaveCount(2);
});

it('validates the client form', function (string $field, mixed $value, string $rule) {
    livewire(CreateClient::class)
        ->fillForm(['owner_name' => 'Jane Doe', 'email' => 'owner@example.test', 'type' => ClientType::Maui->value, $field => $value])
        ->call('create')
        ->assertHasFormErrors([$field => $rule]);
})->with([
    'missing email' => ['email', null, 'required'],
    'not an email' => ['email', 'nope', 'email'],
    'missing owner name' => ['owner_name', null, 'required'],
    'too long owner name' => ['owner_name', str_repeat('a', 256), 'max'],
]);

it('finds the cabinets of an owner by name or email', function () {
    $janes = Client::factory()->count(2)->create(['owner_name' => 'Jane Doe', 'email' => 'jane@example.test']);
    $other = Client::factory()->create(['owner_name' => 'John Smith', 'email' => 'john@example.test']);

    livewire(ListClients::class)
        ->searchTable('Jane')
        ->assertCanSeeTableRecords($janes)
        ->assertCanNotSeeTableRecords([$other]);

    livewire(ListClients::class)
        ->searchTable('jane@example.test')
        ->assertCanSeeTableRecords($janes)
        ->assertCanNotSeeTableRecords([$other]);
});

describe('cabinet actions', function () {
    it('invites a new cabinet and shows the link once', function () {
        $client = Client::factory()->create();

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionVisible('invite')
            ->assertActionHidden('renew')
            ->callAction('invite')
            ->assertActionMounted('showSecret')
            ->assertMountedActionModalSee(url('/invite/'));

        expect($client->invitations()->count())->toBe(1);
    });

    it('offers a renewal once the cabinet has credentials', function () {
        [$client] = cabinetWithToken();

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('invite')
            ->callAction('renew')
            ->assertActionMounted('showSecret');

        expect($client->invitations()->first()->purpose)->toBe(InvitationPurpose::Renewal)
            ->and($client->tokens()->count())->toBe(1);
    });

    it('disables then re-enables a client', function () {
        $client = Client::factory()->create();

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('enable')
            ->callAction('disable');
        expect($client->fresh()->status)->toBe(ClientStatus::Disabled);

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('disable')
            ->callAction('enable');
        expect($client->fresh()->status)->toBe(ClientStatus::Active);
    });

    it('resets the machine binding of a bound cabinet only', function () {
        [$client, $token] = cabinetWithToken();

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])->assertActionHidden('resetBinding');

        $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))->assertOk();

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])->callAction('resetBinding');
        expect($client->fresh()->machine_fingerprint_hash)->toBeNull();
    });

    it('does not offer a service token to a cabinet', function () {
        livewire(ViewClient::class, ['record' => Client::factory()->create()->getRouteKey()])
            ->assertActionHidden('issueServiceToken');
    });
});

describe('service account actions', function () {
    it('issues a token shown once, and no invitation', function () {
        $client = Client::factory()->service()->create();

        livewire(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('invite')
            ->assertActionHidden('renew')
            ->callAction('issueServiceToken')
            ->assertActionMounted('showSecret');

        expect($client->tokens()->count())->toBe(1);
    });
});

it('shows the readable OS name of the latest startup, or the kernel when unknown', function (?string $osName, string $shown) {
    $client = Client::factory()->create();
    $client->recordStartup([
        'mame_version' => '0.289', 'maui_version' => '2.5.0', 'os' => 'linux',
        'os_version' => '6.8.0-139-generic', 'os_name' => $osName, 'client_datetime' => now(),
    ]);

    livewire(ViewClient::class, ['record' => $client->getRouteKey()])->assertSee($shown);
})->with([
    'with OS name' => ['Ubuntu 24.04.5 LTS', 'Ubuntu 24.04.5 LTS'],
    'without OS name' => [null, 'linux 6.8.0-139-generic'],
]);

it('shows the OS name next to the kernel version in the startup history', function () {
    $client = Client::factory()->create();
    $client->recordStartup([
        'mame_version' => '0.289', 'maui_version' => '2.5.0', 'os' => 'win32',
        'os_version' => '10.0.22631', 'os_name' => 'Windows 11 (build 22631)', 'client_datetime' => now(),
    ]);

    livewire(StartupsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertSee('OS name')
        ->assertSee('Windows 11 (build 22631)')
        ->assertSee('10.0.22631');
});

it('shows the startup history of a cabinet', function () {
    $client = Client::factory()->create();
    $startup = $client->startups()->create([
        'mame_version' => '0.272', 'maui_version' => '2.4.0', 'os' => 'linux',
        'os_version' => '6.8.0', 'client_datetime' => now(), 'received_at' => now(),
    ]);

    livewire(StartupsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->assertCanSeeTableRecords([$startup]);
});
