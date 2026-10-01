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

    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'regex:'.self::PATTERN];
    }
}
