<?php

use App\Enums\ScoreIntegrity;
use App\Services\Scores\IntegrityVerdict;

// The verdict on the cabinet's report on a game (docs/DECISIONS.md D87).

/**
 * A report of a fair game, as MAUI sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cleanReport(array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'options' => ['cheat' => false, 'rewind' => false, 'autosave' => false, 'console' => false, 'throttle' => true, 'speed' => 1.0],
        'plugins' => ['hiscore', 'data', 'layout'],
        'rom' => 'good',
        'dips' => [],
        'events' => ['state_loads' => 0, 'state_saves' => 0, 'pauses' => 1, 'pause_s' => 4.5, 'resets' => 1],
        'speed' => ['average_percent' => 99.7, 'below_95_s' => 0, 'unthrottled_s' => 0, 'fast_forward_s' => 0],
    ], $overrides);
}

it('finds a fair game clean, a short pause and a reset included', function () {
    expect(IntegrityVerdict::judge(cleanReport()))->toBe(['verdict' => ScoreIntegrity::Clean, 'reasons' => []]);
});

it('finds suspect what makes a game easier', function (array $overrides, string $reason) {
    $judged = IntegrityVerdict::judge(cleanReport($overrides));

    expect($judged['verdict'])->toBe(ScoreIntegrity::Suspect)
        ->and($judged['reasons'])->toContain($reason);
})->with([
    'a state loaded' => [['events' => ['state_loads' => 1]], 'state_loaded'],
    'the cheat plugin' => [['plugins' => ['hiscore', 'cheat']], 'cheat_plugin'],
    'autofire' => [['plugins' => ['autofire']], 'cheat_plugin'],
    'cheats on' => [['options' => ['cheat' => true]], 'cheat_option'],
    'rewind on' => [['options' => ['rewind' => true]], 'rewind'],
    'the console on' => [['options' => ['console' => true]], 'console'],
    'a bad ROM' => [['rom' => 'bad'], 'rom_bad'],
    'too slow' => [['speed' => ['average_percent' => 82.0]], 'slow'],
]);

it('finds to check what may be legitimate', function (array $overrides, string $reason) {
    $judged = IntegrityVerdict::judge(cleanReport($overrides));

    expect($judged['verdict'])->toBe(ScoreIntegrity::Check)
        ->and($judged['reasons'])->toBe([$reason]);
})->with([
    'a DIP switch' => [['dips' => [['name' => 'Lives', 'value' => '5', 'default' => '3']]], 'dip_changed'],
    'long pauses' => [['events' => ['pause_s' => 45]], 'long_pauses'],
    'many pauses' => [['events' => ['pauses' => 11]], 'long_pauses'],
    'a state saved' => [['events' => ['state_saves' => 1]], 'state_saved'],
    'fast forward' => [['speed' => ['fast_forward_s' => 3]], 'speed_changed'],
    'throttle off' => [['options' => ['throttle' => false]], 'speed_changed'],
    'another speed' => [['options' => ['speed' => 1.5]], 'speed_changed'],
    'autosave on' => [['options' => ['autosave' => true]], 'autosave'],
    'an unknown plugin' => [['plugins' => ['hiscore', 'mytool']], 'unknown_plugin'],
]);

it('keeps suspect over to check, with every reason', function () {
    $judged = IntegrityVerdict::judge(cleanReport([
        'events' => ['state_loads' => 2],
        'dips' => [['name' => 'Lives', 'value' => '5', 'default' => '3']],
    ]));

    expect($judged['verdict'])->toBe(ScoreIntegrity::Suspect)
        ->and($judged['reasons'])->toBe(['state_loaded', 'dip_changed']);
});

it('reads a report with parts missing as far as it goes', function () {
    expect(IntegrityVerdict::judge(['version' => 1]))->toBe(['verdict' => ScoreIntegrity::Clean, 'reasons' => []]);
});

it('names every reason', function () {
    foreach (['state_loaded', 'cheat_plugin', 'cheat_option', 'rewind', 'console', 'rom_bad', 'slow', 'dip_changed',
        'long_pauses', 'state_saved', 'speed_changed', 'autosave', 'unknown_plugin'] as $reason) {
        expect(IntegrityVerdict::label($reason))->not->toBe($reason);
    }
});
