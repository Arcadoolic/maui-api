<?php

namespace App\Services\Scores;

use App\Enums\ScoreIntegrity;

/**
 * Judges the cabinet's report on the game a score was made in (docs/DECISIONS.md
 * D87). The report is kept raw on the score and only judged here, so that the
 * rules can change without touching the cabinets. Each reason is a stable code,
 * named in the back office by label().
 */
final class IntegrityVerdict
{
    /** Plugins that play for the player, find or apply cheats, or poke at the machine. */
    private const CHEAT_PLUGINS = ['cheat', 'cheatfind', 'autofire', 'inputmacro', 'console', 'gdbstub'];

    /** Plugins that change nothing to a game. Anything else is worth a look. */
    private const HARMLESS_PLUGINS = ['hiscore', 'data', 'layout', 'discord', 'portname', 'timecode', 'timer', 'dummy', 'vector'];

    /** Below this average speed (percent of real time), the game ran slow enough to be easier. */
    private const SLOW_PERCENT = 90;

    /** Paused longer than this in all (seconds), or more often than MAX_PAUSES. */
    private const MAX_PAUSE_SECONDS = 30;

    private const MAX_PAUSES = 10;

    /** Reasons that make a score suspect; any other makes it to check. */
    private const SUSPECT_REASONS = ['state_loaded', 'cheat_plugin', 'cheat_option', 'rewind', 'console', 'rom_bad', 'slow'];

    /**
     * @param  array<string, mixed>  $report
     * @return array{verdict: ScoreIntegrity, reasons: list<string>}
     */
    public static function judge(array $report): array
    {
        $reasons = [];
        $options = self::map($report['options'] ?? null);
        $events = self::map($report['events'] ?? null);
        $speed = self::map($report['speed'] ?? null);
        $plugins = array_values(array_filter(is_array($report['plugins'] ?? null) ? $report['plugins'] : [], 'is_string'));

        if (self::number($events['state_loads'] ?? 0) > 0) {
            $reasons[] = 'state_loaded';
        }
        if (array_intersect($plugins, self::CHEAT_PLUGINS) !== []) {
            $reasons[] = 'cheat_plugin';
        }
        if (($options['cheat'] ?? false) === true) {
            $reasons[] = 'cheat_option';
        }
        if (($options['rewind'] ?? false) === true) {
            $reasons[] = 'rewind';
        }
        if (($options['console'] ?? false) === true) {
            $reasons[] = 'console';
        }
        if (($report['rom'] ?? null) === 'bad') {
            $reasons[] = 'rom_bad';
        }
        if (isset($speed['average_percent']) && self::number($speed['average_percent']) < self::SLOW_PERCENT) {
            $reasons[] = 'slow';
        }

        if (is_array($report['dips'] ?? null) && $report['dips'] !== []) {
            $reasons[] = 'dip_changed';
        }
        if (self::number($events['pause_s'] ?? 0) > self::MAX_PAUSE_SECONDS || self::number($events['pauses'] ?? 0) > self::MAX_PAUSES) {
            $reasons[] = 'long_pauses';
        }
        if (self::number($events['state_saves'] ?? 0) > 0) {
            $reasons[] = 'state_saved';
        }
        if (self::number($speed['unthrottled_s'] ?? 0) > 0 || self::number($speed['fast_forward_s'] ?? 0) > 0
            || ($options['throttle'] ?? true) === false || self::number($options['speed'] ?? 1) != 1) {
            $reasons[] = 'speed_changed';
        }
        if (($options['autosave'] ?? false) === true) {
            $reasons[] = 'autosave';
        }
        if (array_diff($plugins, self::CHEAT_PLUGINS, self::HARMLESS_PLUGINS) !== []) {
            $reasons[] = 'unknown_plugin';
        }

        $verdict = array_intersect($reasons, self::SUSPECT_REASONS) !== [] ? ScoreIntegrity::Suspect
            : ($reasons === [] ? ScoreIntegrity::Clean : ScoreIntegrity::Check);

        return ['verdict' => $verdict, 'reasons' => $reasons];
    }

    public static function label(string $reason): string
    {
        return match ($reason) {
            'state_loaded' => __('A saved state was loaded'),
            'cheat_plugin' => __('A cheat, autofire or console plugin was running'),
            'cheat_option' => __('Cheats were enabled'),
            'rewind' => __('Rewind was enabled'),
            'console' => __('The Lua console was enabled'),
            'rom_bad' => __('The ROM does not match MAME\'s'),
            'slow' => __('The game ran slower than 90% of real time'),
            'dip_changed' => __('DIP switches differ from the game\'s defaults'),
            'long_pauses' => __('Long or many pauses'),
            'state_saved' => __('A state was saved'),
            'speed_changed' => __('The speed was changed (throttle off, fast forward)'),
            'autosave' => __('Autosave was enabled'),
            'unknown_plugin' => __('An unknown plugin was running'),
            default => $reason,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
