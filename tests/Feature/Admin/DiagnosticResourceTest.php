<?php

use App\Filament\Resources\Diagnostics\Pages\ListDiagnostics;
use App\Models\Diagnostic;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticArchive;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// Diagnostics in the back office: read, download, delete (docs/DECISIONS.md D92).

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin);
});

it('lists the diagnostics, latest first', function () {
    $diagnostics = Diagnostic::factory()->count(2)->create();

    livewire(ListDiagnostics::class)->assertCanSeeTableRecords($diagnostics);
});

it('downloads a diagnostic as a zip, with the admin in the audit log', function () {
    $diagnostic = Diagnostic::factory()->withSaveram()->create();

    livewire(ListDiagnostics::class)
        ->callTableAction('download', $diagnostic)
        ->assertFileDownloaded("diagnostic-mslug-{$diagnostic->received_at->format('Ymd-His')}.zip");

    $activity = Activity::query()->whereMorphedTo('subject', $diagnostic)->sole();
    expect($activity->event)->toBe('diagnostic.downloaded')
        ->and($activity->causer_id)->toBe($this->admin->id);
});

it('deletes a diagnostic with its files', function () {
    $diagnostic = Diagnostic::factory()->withSaveram()->create();

    livewire(ListDiagnostics::class)->callTableAction('delete', $diagnostic);

    expect(Diagnostic::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($diagnostic->path('nvram', 'saveram'));
});

it('lays the zip out as mhiex reads a MAME home', function () {
    $diagnostic = Diagnostic::factory()->create([
        'romname' => 'centiped',
        'comment' => 'Missing 15000',
        'files' => [
            ['area' => 'hiscore', 'name' => 'centiped.hi', 'size' => 3, 'modified' => null, 'sha256' => hash('sha256', 'abc')],
            ['area' => 'nvram', 'name' => 'earom', 'size' => 3, 'modified' => null, 'sha256' => hash('sha256', 'def')],
            ['area' => 'logs', 'name' => 'maui.log', 'size' => 3, 'modified' => null, 'sha256' => hash('sha256', 'ghi')],
        ],
    ]);
    Storage::disk('local')->put($diagnostic->path('hiscore', 'centiped.hi'), 'abc');
    Storage::disk('local')->put($diagnostic->path('nvram', 'earom'), 'def');
    Storage::disk('local')->put($diagnostic->path('logs', 'maui.log'), 'ghi');

    $path = app(DiagnosticArchive::class)->build($diagnostic);
    $zip = new ZipArchive;
    $zip->open($path);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $meta = json_decode((string) $zip->getFromName('diagnostic.json'), true);
    $earom = $zip->getFromName('nvram/centiped/earom');
    $zip->close();
    unlink($path);

    expect($names)->toEqualCanonicalizing(['hiscore/centiped.hi', 'nvram/centiped/earom', 'logs/maui.log', 'diagnostic.json'])
        ->and($earom)->toBe('def')
        ->and($meta['romname'])->toBe('centiped')
        ->and($meta['comment'])->toBe('Missing 15000')
        ->and($meta['cabinet'])->toBe($diagnostic->client->name)
        ->and($meta['files'])->toHaveCount(3);
});
