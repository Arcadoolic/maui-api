<?php

use App\Models\Diagnostic;
use Illuminate\Support\Facades\Storage;

// Diagnostics are kept 90 days (docs/DECISIONS.md D92).

beforeEach(fn () => Storage::fake('local'));

it('deletes the diagnostics older than 90 days, with their files', function () {
    $old = Diagnostic::factory()->withSaveram()->create(['received_at' => now()->subDays(91)]);
    $recent = Diagnostic::factory()->withSaveram()->create(['received_at' => now()->subDays(89)]);

    $this->artisan('diagnostics:purge')
        ->expectsOutput('1 diagnostic(s) older than 90 days deleted.')
        ->assertSuccessful();

    expect(Diagnostic::query()->pluck('id')->all())->toBe([$recent->id]);
    Storage::disk('local')->assertMissing($old->path('nvram', 'saveram'));
    Storage::disk('local')->assertExists($recent->path('nvram', 'saveram'));
});
