<?php

namespace App\Enums;

/** What a game's votes and activity say of it, at most one (docs/DECISIONS.md D76). */
enum PopularityLabel: string
{
    /** Liked and played. */
    case Hit = 'hit';

    /** Liked, hardly played. */
    case HiddenGem = 'hidden_gem';

    /** Played a lot, without the votes to call it liked. */
    case Addictive = 'addictive';

    /** About as many thumbs up as thumbs down. */
    case Divisive = 'divisive';

    /** Thumbs down from every cabinet that voted. */
    case MissedDate = 'missed_date';
}
