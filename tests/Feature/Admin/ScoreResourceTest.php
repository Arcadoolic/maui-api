<?php

use App\Filament\Resources\Scores\Pages\ListScores;
use App\Filament\Widgets\LatestScores;
use App\Models\Score;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// Scores in the back office: moderation only (docs/DECISIONS.md D50).

beforeEach(function () {
    $this->admin = User::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin);
});

it('lists scores', function () {
    $scores = Score::factory()->count(3)->create();

    livewire(ListScores::class)->assertCanSeeTableRecords($scores);
});

it('filters hidden scores', function () {
    $hidden = Score::factory()->hidden()->create();
    $shown = Score::factory()->create();

    livewire(ListScores::class)
        ->filterTable('hidden', true)
        ->assertCanSeeTableRecords([$hidden])
        ->assertCanNotSeeTableRecords([$shown]);
});

it('filters the scores declared on a cabinet', function () {
    $declared = Score::factory()->declared()->create();
    $read = Score::factory()->create();

    livewire(ListScores::class)
        ->assertTableColumnExists('attribution')
        ->filterTable('attribution', 'declared')
        ->assertCanSeeTableRecords([$declared])
        ->assertCanNotSeeTableRecords([$read]);
});

it('hides a score and shows it again, with the admin in the audit log', function () {
    $score = Score::factory()->create();

    livewire(ListScores::class)->callTableAction('hide', $score);
    expect($score->refresh()->isHidden())->toBeTrue();

    livewire(ListScores::class)->callTableAction('show', $score);
    expect($score->refresh()->isHidden())->toBeFalse();

    $events = Activity::query()->whereMorphedTo('subject', $score)->where('causer_id', $this->admin->id)->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['score.hidden', 'score.shown']);
});

it('lists the latest scores on the dashboard', function () {
    $scores = Score::factory()->count(2)->create();

    livewire(LatestScores::class)->assertCanSeeTableRecords($scores);
});

it('shows what the cabinet saw of the game, and filters on it (D87)', function () {
    $suspect = Score::factory()->create([
        'integrity' => ['version' => 1, 'events' => ['state_loads' => 1]],
        'integrity_verdict' => 'suspect',
        'integrity_reasons' => ['state_loaded'],
    ]);
    $clean = Score::factory()->create([
        'integrity' => ['version' => 1], 'integrity_verdict' => 'clean', 'integrity_reasons' => [],
    ]);
    $unknown = Score::factory()->create();

    livewire(ListScores::class)
        ->assertTableColumnExists('integrity_verdict')
        ->filterTable('integrity_verdict', 'suspect')
        ->assertCanSeeTableRecords([$suspect])
        ->assertCanNotSeeTableRecords([$clean, $unknown]);

    livewire(ListScores::class)
        ->assertTableActionVisible('integrity', $suspect)
        ->assertTableActionHidden('integrity', $unknown)
        ->mountTableAction('integrity', $suspect)
        ->assertMountedActionModalSee(['Suspect', 'A saved state was loaded', 'state_loads']);
});
