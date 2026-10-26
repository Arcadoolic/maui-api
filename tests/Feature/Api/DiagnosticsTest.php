<?php

use App\Models\Diagnostic;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// Diagnostics sent from a cabinet's back office (docs/DECISIONS.md D92).

beforeEach(fn () => Storage::fake('local'));

const DIAGNOSTIC_LOG_LINE = "[2026-10-25 17:21:42.655] [debug] [HiscoreService] Scores to save for game 6.\n";

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function diagnosticPayload(array $overrides = []): array
{
    return [
        'romname' => 'mslug',
        'comment' => '300k, NOB, 25/10 around 17:00',
        'report' => json_encode(['versions' => ['maui' => '2.18.0', 'mhiex' => '2.2.6', 'mame' => '0.289'], 'table' => []]),
        'files' => [
            ['area' => 'nvram', 'name' => 'saveram', 'modified' => '2026-10-25T16:21:59Z', 'content' => UploadedFile::fake()->createWithContent('saveram', str_repeat("\x00", 4096))],
            ['area' => 'logs', 'name' => 'maui.log', 'modified' => '2026-10-26T09:00:00Z', 'content' => UploadedFile::fake()->createWithContent('maui.log', DIAGNOSTIC_LOG_LINE)],
        ],
        ...$overrides,
    ];
}

function postDiagnostic(array $payload, ?array $cabinet = null): TestResponse
{
    [$client, $token] = $cabinet ?? cabinetWithToken();

    return test()->post('/api/v1/diagnostics', $payload, [...cabinetHeaders($client, $token), 'Accept' => 'application/json']);
}

it('stores the files of a game and what the cabinet says about them', function () {
    $cabinet = cabinetWithToken();
    $saveram = str_repeat("\x00", 4096);

    $response = postDiagnostic(diagnosticPayload(), $cabinet)
        ->assertCreated()
        ->assertJsonStructure(['id', 'received_at']);

    $diagnostic = Diagnostic::query()->where('uuid', $response->json('id'))->sole();
    expect($diagnostic->client_id)->toBe($cabinet[0]->id)
        ->and($diagnostic->romname)->toBe('mslug')
        ->and($diagnostic->comment)->toBe('300k, NOB, 25/10 around 17:00')
        ->and($diagnostic->report['versions']['mhiex'])->toBe('2.2.6')
        ->and($diagnostic->has_logs)->toBeTrue()
        ->and($diagnostic->size)->toBe(4096 + strlen(DIAGNOSTIC_LOG_LINE))
        ->and($diagnostic->files)->toHaveCount(2)
        ->and($diagnostic->files[0])->toMatchArray([
            'area' => 'nvram', 'name' => 'saveram', 'size' => 4096, 'sha256' => hash('sha256', $saveram),
        ]);
    Storage::disk('local')->assertExists("diagnostics/{$diagnostic->uuid}/nvram/saveram");
    expect(Storage::disk('local')->get("diagnostics/{$diagnostic->uuid}/nvram/saveram"))->toBe($saveram);
});

it('takes a game with a .hi and an nvram, and no comment', function () {
    postDiagnostic(diagnosticPayload([
        'romname' => 'centiped',
        'comment' => null,
        'files' => [
            ['area' => 'hiscore', 'name' => 'centiped.hi', 'content' => UploadedFile::fake()->createWithContent('centiped.hi', 'abc')],
            ['area' => 'nvram', 'name' => 'earom', 'content' => UploadedFile::fake()->createWithContent('earom', 'def')],
        ],
    ]))->assertCreated();

    expect(Diagnostic::query()->sole())
        ->comment->toBeNull()
        ->has_logs->toBeFalse();
});

it('takes a game without any file, its report saying why', function () {
    postDiagnostic(diagnosticPayload(['files' => []]))->assertCreated();

    expect(Diagnostic::query()->sole()->files)->toBe([]);
});

it('refuses what does not belong to the game or to a diagnostic', function (array $overrides) {
    postDiagnostic(diagnosticPayload($overrides))->assertUnprocessable();

    expect(Diagnostic::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'bad romname' => [['romname' => '../etc']],
    'unknown area' => [['files' => [['area' => 'roms', 'name' => 'mslug.zip', 'content' => UploadedFile::fake()->createWithContent('mslug.zip', 'x')]]]],
    'path in a name' => [['files' => [['area' => 'nvram', 'name' => '../saveram', 'content' => UploadedFile::fake()->createWithContent('saveram', 'x')]]]],
    'hidden name' => [['files' => [['area' => 'nvram', 'name' => '.saveram', 'content' => UploadedFile::fake()->createWithContent('saveram', 'x')]]]],
    '.hi of another game' => [['files' => [['area' => 'hiscore', 'name' => 'dkong.hi', 'content' => UploadedFile::fake()->createWithContent('dkong.hi', 'x')]]]],
    'same file twice' => [['files' => [
        ['area' => 'nvram', 'name' => 'saveram', 'content' => UploadedFile::fake()->createWithContent('saveram', 'x')],
        ['area' => 'nvram', 'name' => 'saveram', 'content' => UploadedFile::fake()->createWithContent('saveram', 'y')],
    ]]],
    'no content' => [['files' => [['area' => 'nvram', 'name' => 'saveram']]]],
    'report not JSON' => [['report' => 'not json']],
    'comment too long' => [['comment' => str_repeat('a', 1001)]],
]);

it('refuses more than 16 files or 1 MB in all', function (array $files) {
    postDiagnostic(diagnosticPayload(['files' => $files]))->assertUnprocessable();

    expect(Diagnostic::query()->count())->toBe(0);
})->with([
    '17 files' => [array_map(fn (int $i): array => ['area' => 'logs', 'name' => "maui-{$i}.log.gz", 'content' => UploadedFile::fake()->createWithContent("maui-{$i}.log.gz", 'x')], range(1, 17))],
    'over 1 MB' => [[
        ['area' => 'logs', 'name' => 'maui.log', 'content' => UploadedFile::fake()->createWithContent('maui.log', str_repeat('a', 600 * 1024))],
        ['area' => 'logs', 'name' => 'maui-20261020-101010.log.gz', 'content' => UploadedFile::fake()->createWithContent('maui-20261020-101010.log.gz', str_repeat('b', 500 * 1024))],
    ]],
]);

it('needs the diagnostics:write ability', function () {
    [$client, $token] = cabinetWithToken();
    $client->tokens()->update(['abilities' => json_encode(['session', 'scores:write'])]);

    postDiagnostic(diagnosticPayload(), [$client, $token])
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

it('takes a few diagnostics an hour from a cabinet, no more', function () {
    $cabinet = cabinetWithToken();
    foreach (range(1, 6) as $ignored) {
        postDiagnostic(diagnosticPayload(['files' => []]), $cabinet)->assertCreated();
    }

    postDiagnostic(diagnosticPayload(['files' => []]), $cabinet)->assertTooManyRequests();
});

it('grants diagnostics:write to the cabinet tokens issued before the ability existed', function () {
    [$cabinet] = cabinetWithToken();
    [$service] = serviceWithToken();
    $before = ['session', 'scores:write', 'scores:read', 'repository:read', 'players'];
    DB::table('personal_access_tokens')->where('tokenable_id', $cabinet->id)
        ->update(['abilities' => json_encode($before)]);
    DB::table('personal_access_tokens')->where('tokenable_id', $service->id)
        ->update(['abilities' => json_encode(['catalog:write', 'repository:read'])]);

    $migration = require database_path('migrations/2026_10_26_120001_grant_diagnostics_write_to_cabinet_tokens.php');
    $migration->up();
    $migration->up(); // idempotent

    expect($cabinet->tokens()->first()->abilities)->toBe([...$before, 'diagnostics:write'])
        ->and($service->tokens()->first()->abilities)->toBe(['catalog:write', 'repository:read']);

    $migration->down();

    expect($cabinet->tokens()->first()->abilities)->toBe($before);
});
