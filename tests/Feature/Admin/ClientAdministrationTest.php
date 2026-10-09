<?php

use App\Enums\ClientStatus;
use App\Enums\InvitationPurpose;
use App\Models\Client;
use App\Models\Player;
use App\Models\Score;
use App\Models\ScoreEvent;
use App\Models\User;
use App\Services\ClientAdministration;
use App\Services\Scores\ScoreEventRecorder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
    $this->administration = app(ClientAdministration::class);
});

/** The last audit entry for this client and event. */
function auditEntry(Client $client, string $event): ?Activity
{
    return Activity::query()
        ->where('subject_type', $client->getMorphClass())
        ->where('subject_id', $client->getKey())
        ->where('event', $event)
        ->latest('id')
        ->first();
}

it('invites a cabinet and records who did it, without the secret', function () {
    $client = Client::factory()->create();

    $issued = $this->administration->invite($client);

    expect($issued->invitation->purpose)->toBe(InvitationPurpose::Initial)
        ->and($issued->invitation->created_by)->toBe($this->admin->id);

    $entry = auditEntry($client, 'client.invited');
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and(json_encode($entry->properties))->not->toContain($issued->plainToken);
});

it('requests a renewal without revoking the current token yet', function () {
    [$client, $token] = cabinetWithToken();

    $issued = $this->administration->renew($client);

    expect($issued->invitation->purpose)->toBe(InvitationPurpose::Renewal)
        ->and(auditEntry($client, 'client.renewal_requested'))->not->toBeNull();
    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))->assertOk();
});

it('resets the machine binding and records it', function () {
    [$client, $token] = cabinetWithToken();
    $this->getJson('/api/v1/ping', cabinetHeaders($client, $token, fingerprint('cabinet-a')))->assertOk();

    $this->administration->resetMachineBinding($client);

    expect($client->fresh()->machine_fingerprint_hash)->toBeNull()
        ->and(auditEntry($client, 'client.binding_reset'))->not->toBeNull();
});

it('disables and re-enables a client, both recorded', function () {
    $client = Client::factory()->create();

    $this->administration->disable($client);
    expect($client->fresh()->status)->toBe(ClientStatus::Disabled)
        ->and(auditEntry($client, 'client.disabled'))->not->toBeNull();

    $this->administration->enable($client);
    expect($client->fresh()->status)->toBe(ClientStatus::Active)
        ->and(auditEntry($client, 'client.enabled'))->not->toBeNull();
});

it('issues a service account token once, without logging it', function () {
    $client = Client::factory()->service()->create();

    $token = $this->administration->issueServiceToken($client);

    expect($client->tokens()->count())->toBe(1)
        ->and($client->tokens()->first()->abilities)->toBe(['catalog:write', 'repository:read']);

    $entry = auditEntry($client, 'client.service_token_issued');
    expect($entry)->not->toBeNull()
        ->and(json_encode($entry->properties))->not->toContain($token);
});

it('refuses service tokens for cabinets: they use invitations', function () {
    $this->administration->issueServiceToken(Client::factory()->create());
})->throws(InvalidArgumentException::class);

it('records client creation and profile changes with the admin as causer', function () {
    $client = Client::factory()->create(['email' => 'owner@example.test']);
    $client->update(['notes' => 'Cabinet in the garage', 'owner_name' => 'Jane Doe']);

    $created = auditEntry($client, 'created');
    $updated = auditEntry($client, 'updated');

    expect($created->causer_id)->toBe($this->admin->id)
        ->and($updated->causer_id)->toBe($this->admin->id)
        ->and($updated->attribute_changes['attributes'])->toEqualCanonicalizing(['notes' => 'Cabinet in the garage', 'owner_name' => 'Jane Doe']);
});

it('does not record technical updates such as heartbeats', function () {
    $client = Client::factory()->create();
    $before = Activity::count();

    $client->recordHeartbeat();

    expect(Activity::count())->toBe($before);
});

describe('deletion (D63)', function () {
    it('deletes a client with everything attached to it, scores included', function () {
        [$client, $token] = cabinetWithToken();
        $this->administration->invite($client);
        $client->recordStartup([
            'mame_version' => '0.289', 'maui_version' => '2.5.0', 'os' => 'linux',
            'os_version' => '6.8.0', 'client_datetime' => now(),
        ]);
        $player = Player::factory()->create(['origin_client_id' => $client->id, 'is_public' => true]);
        $client->players()->attach($player, ['linked_at' => now()]);
        $score = Score::factory()->for($client)->for($player)->create();
        expect(app(ScoreEventRecorder::class)->record($score, null))->not->toBeNull();

        $this->administration->delete($client);

        expect(Client::query()->find($client->id))->toBeNull()
            ->and(Score::query()->count())->toBe(0)
            ->and(ScoreEvent::query()->count())->toBe(0)
            ->and(DB::table('invitations')->count())->toBe(0)
            ->and(DB::table('client_startups')->count())->toBe(0)
            ->and(DB::table('client_player')->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->count())->toBe(0);
        $this->getJson('/api/v1/ping', cabinetHeaders($client, $token))->assertUnauthorized();
    });

    it('keeps the players and the scores of the other cabinets, only unlinked', function () {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $player = Player::factory()->create(['origin_client_id' => $client->id]);
        $client->players()->attach($player, ['linked_at' => now()]);
        $other->players()->attach($player, ['linked_at' => now()]);
        Score::factory()->for($client)->for($player)->create();
        $kept = Score::factory()->for($other)->for($player)->create();

        $this->administration->delete($client);

        expect($player->fresh())->not->toBeNull()
            ->and($player->fresh()->origin_client_id)->toBeNull()
            ->and($other->players()->pluck('players.id')->all())->toBe([$player->id])
            ->and(Score::query()->pluck('id')->all())->toBe([$kept->id]);
    });

    it('records the deletion in the kept audit trail', function () {
        $client = Client::factory()->create(['name' => 'glitchy_pac_man']);
        $this->administration->disable($client);
        Score::factory()->count(2)->for($client)->create();

        $this->administration->delete($client);

        $entry = auditEntry($client, 'client.deleted');
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($this->admin->id)
            ->and($entry->properties->all())->toMatchArray(['name' => 'glitchy_pac_man', 'scores' => 2, 'players' => 0])
            ->and(auditEntry($client, 'client.disabled'))->not->toBeNull()
            ->and(auditEntry($client, 'deleted'))->toBeNull();
    });
});
