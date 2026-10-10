<?php

return [

    /*
    | Thresholds of the score events: when a score earns a flavor, and when
    | it is too old to be announced (docs/DECISIONS.md D60).
    */
    'events' => [
        // A score made more than this many hours ago is stored, not announced.
        'stale_after_hours' => 24,
        // `huge_jump`: the score is at least this many times the previous best.
        'huge_jump_factor' => 2,
        // `crushing`: the new leader beats the previous one by this percentage.
        'crushing_percent' => 50,
        // `photo_finish`: ahead of the displaced player by less than this percentage.
        'photo_finish_percent' => 1,
        // `leapfrog`: players overtaken at once.
        'leapfrog_players' => 3,
        // `on_a_roll`: every this many personal bests on the game within the days.
        'roll_scores' => 3,
        'roll_days' => 7,
        // `comeback`: days since the previous best on the game.
        'comeback_days' => 30,
        // `reign_ended`: days the dethroned leader held the first place.
        'reign_days' => 30,
        // `rivalry`: times the two players took this place from each other.
        'rivalry_rounds' => 3,
        // `milestone`: 1 and 5 times each power of ten from this score up.
        'milestone_from' => 100_000,
        // `multi_crown`: leaderboards led; `collector`: games with a score.
        'crowns' => [3, 5, 10, 25, 50],
        'games' => [10, 25, 50, 100],
    ],

    /*
    | The global podium of the hiscores front (docs/DECISIONS.md D70). A
    | leaderboard gives base points for the rank, times a competition factor:
    | min(1, (ranked players - 1) / (full_competition_players - 1)). Alone on
    | a game: nothing. A player's total is the sum of its best results.
    */
    'ranking' => [
        // Points of the first ranks; then `step` less per rank, `floor` at least.
        'base' => [100, 80, 65, 55, 45, 38, 32, 26, 20],
        'step' => 2,
        'floor' => 5,
        // Ranked players from which a leaderboard gives its full points. Low
        // while the players are few: to raise as they come.
        'full_competition_players' => (int) env('HISCORES_FULL_COMPETITION_PLAYERS', 5),
        // Results counted per player: one per game, the best ones.
        'best_results' => 15,
    ],

    /*
    | Popularity of the games, from the cabinets' votes and plays and from
    | the ranked players (docs/DECISIONS.md D76, App\Services\Popularity).
    | First settings, made on a simulated fleet: to tune with
    | `php artisan hiscores:popularity` once real cabinets report.
    */
    'popularity' => [
        // Share of the index given by the opinion and by the activity.
        'opinion_weight' => 0.6,
        'activity_weight' => 0.4,
        // Imaginary votes at the fleet's average added to each game: the
        // higher, the more votes a game needs to leave the average.
        'prior_votes' => 3,
        // Votes from which a game gets a label about its votes. Low while
        // the cabinets are few: to raise as they come.
        'min_votes' => (int) env('HISCORES_POPULARITY_MIN_VOTES', 3),
        // `hit`, `hidden_gem`: thumbs up among the votes, at least.
        'liked_share' => 0.75,
        // `divisive`: both votes present, their gap within this share of the votes.
        'divisive_margin' => 0.25,
        // `hit`, `addictive`: activity (0 to 1) from which a game is "played".
        'active_from' => 0.5,
        // Activity: (sum per cabinet of ln(1 + plays), times `plays_weight`,
        // plus the cabinets that played within `recent_days`, times
        // `recent_weight`) / cabinets of the fleet, plus `players_weight` x
        // ln(1 + ranked players). `activity_half` is the activity worth 0.5.
        'plays_weight' => 1.0,
        'recent_weight' => 1.0,
        'players_weight' => 0.5,
        'recent_days' => 30,
        // 2.5 since the first real cabinets: on a young fleet every game was
        // played lately, and 1.5 called a game played 3 times a hit (D80).
        'activity_half' => (float) env('HISCORES_POPULARITY_ACTIVITY_HALF', 2.5),
        // Front, "trending" (D77): days of plays and scores counted.
        'trending_days' => 7,
    ],

];
