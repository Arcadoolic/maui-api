<?php

use App\Enums\ScoreRecoveryStatus;
use App\Filament\Resources\Diagnostics\Pages\ListDiagnostics;
use App\Filament\Resources\ScoreRecoveries\Pages\ListScoreRecoveries;
use App\Models\Diagnostic;
use App\Models\ScoreRecovery;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// Requests to recover a score, in the back office (docs/DECISIONS.md D93).

beforeEach(function () {
    $this->admin = User::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin);
});

it('lists the requests', function () {
    $recoveries = ScoreRecovery::factory()->count(2)->create();

    livewire(ListScoreRecoveries::class)->assertCanSeeTableRecords($recoveries);
});

it('opens a request for a player of the cabinet, with the admin in the audit log', function () {
    [$client] = cabinetWithToken();
    $player = linkedPlayer($client);

    livewire(ListScoreRecoveries::class)
        ->callAction('request', data: [
            'client_id' => $client->id,
            'player_id' => $player->id,
            'romname' => 'atetris',
            'table' => 'default',
            'score' => 250000,
            'achieved_at' => '2026-10-20 21:30:00',
            'reason' => 'Screenshot and .hi checked, extractor fixed in mhiex 2.2.7.',
        ])
        ->assertHasNoActionErrors();

    $recovery = ScoreRecovery::query()->sole();
    expect($recovery->client_id)->toBe($client->id)
        ->and($recovery->player_id)->toBe($player->id)
        ->and($recovery->score)->toBe(250_000)
        ->and($recovery->status)->toBe(ScoreRecoveryStatus::Pending)
        ->and($recovery->expires_at->isFuture())->toBeTrue();
    $activity = Activity::query()->whereMorphedTo('subject', $recovery)->sole();
    expect($activity->event)->toBe('score_recovery.created')
        ->and($activity->causer_id)->toBe($this->admin->id);
});

it('refuses a player the cabinet does not have', function () {
    [$client] = cabinetWithToken();
    [$other] = cabinetWithToken();

    livewire(ListScoreRecoveries::class)
        ->callAction('request', data: [
            'client_id' => $client->id,
            'player_id' => linkedPlayer($other)->id,
            'romname' => 'atetris',
            'table' => 'default',
            'score' => 250000,
            'achieved_at' => '2026-10-20 21:30:00',
            'reason' => 'Checked.',
        ])
        ->assertHasActionErrors(['player_id']);

    expect(ScoreRecovery::query()->count())->toBe(0);
});

it('opens a request from a diagnostic, for its cabinet and game', function () {
    Storage::fake('local');
    [$client] = cabinetWithToken();
    $player = linkedPlayer($client);
    $diagnostic = Diagnostic::factory()->for($client)->create(['romname' => 'atetris']);

    livewire(ListDiagnostics::class)
        ->callTableAction('recover', $diagnostic, data: [
            'player_id' => $player->id,
            'table' => 'default',
            'score' => 250000,
            'achieved_at' => '2026-10-20 21:30:00',
            'reason' => 'In the .hi of the diagnostic.',
        ])
        ->assertHasNoTableActionErrors();

    expect(ScoreRecovery::query()->sole())
        ->client_id->toBe($client->id)
        ->romname->toBe('atetris')
        ->diagnostic_id->toBe($diagnostic->id);
});
