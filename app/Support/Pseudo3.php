<?php

namespace App\Support;

/**
 * Player initials: the first 3 characters of a hiscore name are matched
 * against them, so only what every game's name entry offers is allowed.
 * Same rule as MAUI's src/class/Pseudo3.ts: widen both together.
 */
final class Pseudo3
{
    public const PATTERN = '/^[A-Z]{3}$/';

    /** Three times the same letter (AAA, ZZZ...): refused for new players. */
    public const SAME_LETTER_PATTERN = '/^(.)\\1\\1$/';

    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'regex:'.self::PATTERN];
    }

    /**
     * For a new player: same rule, minus the same letter three times. The
     * players who already have such initials keep them and can still be
     * linked with their PIN (docs/DECISIONS.md D51).
     *
     * @return list<string>
     */
    public static function newPlayerRules(): array
    {
        return [...self::rules(), 'not_regex:'.self::SAME_LETTER_PATTERN];
    }
}
