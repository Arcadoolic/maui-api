<?php

namespace App\Services\Popularity;

use App\Enums\PopularityLabel;
use App\Models\GameOpinion;
use App\Services\Leaderboards\Rankings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Popularity of the games, from what the cabinets report (D75), the votes of
 * the players on the front (D86) and the visible scores (docs/DECISIONS.md D76).
 *
 * - Opinion: thumbs up among the votes, a player's vote counting as a
 *   cabinet's one (D86), as a Bayesian average: `prior_votes`
 *   imaginary votes at the fleet's own share of thumbs up are added, so that
 *   one vote does not make a game the best liked one.
 * - Activity: per cabinet, ln(1 + plays), so that one cabinet playing a
 *   game 500 times does not weigh 500 cabinets, plus the cabinets that
 *   played it lately; both divided by the cabinets of the fleet, so that
 *   the scale holds whatever their number. Then ln(1 + ranked players).
 *   Brought to 0..1 by x / (x + half): `activity_half` is the activity
 *   worth half the scale.
 * - Index: 100 x (opinion_weight x opinion + activity_weight x activity).
 *
 * A game nobody voted on has the fleet's average opinion: such games are
 * sorted by their activity alone.
 *
 * Labels (D94): the liked games are ranked by activity among themselves, the
 * most played share a hit, the least played share a hidden gem; the games
 * not liked among the most played of all are addictive. Ranks, not a fixed
 * activity, so that the labels hold whatever the size of the fleet.
 */
final class Popularity
{
    public function __construct(private readonly Rankings $rankings) {}

    /**
     * @return array{opinion_weight: float, activity_weight: float, prior_votes: float, min_votes: int, liked_share: float, divisive_margin: float, hit_share: float, hidden_gem_share: float, addictive_share: float, activity_half: float, recent_days: int, plays_weight: float, recent_weight: float, players_weight: float}
     */
    public static function rules(): array
    {
        $number = fn (string $key): float => (float) config('hiscores.popularity.'.$key);

        return [
            'opinion_weight' => max(0.0, $number('opinion_weight')),
            'activity_weight' => max(0.0, $number('activity_weight')),
            'prior_votes' => max(0.0, $number('prior_votes')),
            'min_votes' => max(1, (int) $number('min_votes')),
            'liked_share' => min(1.0, max(0.5, $number('liked_share'))),
            'divisive_margin' => min(1.0, max(0.0, $number('divisive_margin'))),
            'hit_share' => min(1.0, max(0.0, $number('hit_share'))),
            'hidden_gem_share' => min(1.0, max(0.0, $number('hidden_gem_share'))),
            'addictive_share' => min(1.0, max(0.0, $number('addictive_share'))),
            'activity_half' => max(0.01, $number('activity_half')),
            'recent_days' => max(1, (int) $number('recent_days')),
            'plays_weight' => max(0.0, $number('plays_weight')),
            'recent_weight' => max(0.0, $number('recent_weight')),
            'players_weight' => max(0.0, $number('players_weight')),
        ];
    }

    /**
     * Every game a cabinet or a player voted on, a cabinet played, or with a visible score,
     * the most popular first.
     *
     * @param  array{opinion_weight: float, activity_weight: float, prior_votes: float, min_votes: int, liked_share: float, divisive_margin: float, hit_share: float, hidden_gem_share: float, addictive_share: float, activity_half: float, recent_days: int, plays_weight: float, recent_weight: float, players_weight: float}|null  $rules
     * @return Collection<int, GamePopularity> Keyed by game id.
     */
    public function all(?array $rules = null): Collection
    {
        $rules ??= self::rules();
        $recentFrom = Carbon::now()->subDays($rules['recent_days']);

        $opinions = DB::table('game_opinions')
            ->groupBy('game_id')
            ->select('game_id')
            ->selectRaw('count(*) filter (where vote = ?) as thumbs_up', [GameOpinion::VOTE_UP])
            ->selectRaw('count(*) filter (where vote = ?) as thumbs_down', [GameOpinion::VOTE_DOWN])
            ->selectRaw('count(*) as cabinets')
            ->selectRaw('coalesce(sum(play_count), 0) as plays')
            ->selectRaw('coalesce(sum(ln(1 + play_count)), 0) as damped_plays')
            ->selectRaw('count(*) filter (where last_played_at >= ?) as recent_cabinets', [$recentFrom])
            ->get()
            ->keyBy('game_id');
        $playerVotes = DB::table('player_opinions')
            ->groupBy('game_id')
            ->select('game_id')
            ->selectRaw('count(*) filter (where vote = ?) as thumbs_up', [GameOpinion::VOTE_UP])
            ->selectRaw('count(*) filter (where vote = ?) as thumbs_down', [GameOpinion::VOTE_DOWN])
            ->get()
            ->keyBy('game_id');
        $players = $this->rankings->gameTotals()->pluck('ranked_players', 'game_id');

        $thumbsUp = (int) $opinions->sum('thumbs_up') + (int) $playerVotes->sum('thumbs_up');
        $votes = $thumbsUp + (int) $opinions->sum('thumbs_down') + (int) $playerVotes->sum('thumbs_down');
        // Nobody voted yet: neither liked nor disliked.
        $fleetShare = $votes > 0 ? $thumbsUp / $votes : 0.5;
        $weights = $rules['opinion_weight'] + $rules['activity_weight'];
        // Cabinets that reported anything: what "played by the whole fleet" is measured against.
        // Not the players: they vote, but report no plays.
        $fleet = max(1, (int) DB::table('game_opinions')->distinct()->count('client_id'));

        $games = $opinions->keys()->merge($playerVotes->keys())->merge($players->keys())->unique()
            ->map(function (int|string $gameId) use ($opinions, $playerVotes, $players, $rules, $fleetShare, $weights, $fleet): array {
                $row = $opinions->get($gameId);
                $byPlayers = $playerVotes->get($gameId);
                $playersUp = (int) ($byPlayers->thumbs_up ?? 0);
                $playersDown = (int) ($byPlayers->thumbs_down ?? 0);
                $up = (int) ($row->thumbs_up ?? 0) + $playersUp;
                $down = (int) ($row->thumbs_down ?? 0) + $playersDown;
                $recent = (int) ($row->recent_cabinets ?? 0);
                $ranked = (int) ($players->get($gameId) ?? 0);

                $opinion = ($up + $rules['prior_votes'] * $fleetShare) / max($up + $down + $rules['prior_votes'], PHP_FLOAT_EPSILON);
                $raw = ($rules['plays_weight'] * (float) ($row->damped_plays ?? 0) + $rules['recent_weight'] * $recent) / $fleet
                    + $rules['players_weight'] * log(1 + $ranked);
                $activity = $raw / ($raw + $rules['activity_half']);
                $index = $weights > 0
                    ? 100 * ($rules['opinion_weight'] * $opinion + $rules['activity_weight'] * $activity) / $weights
                    : 0.0;

                return [
                    'gameId' => (int) $gameId,
                    'thumbsUp' => $up,
                    'thumbsDown' => $down,
                    'cabinets' => (int) ($row->cabinets ?? 0),
                    'playerVotes' => $playersUp + $playersDown,
                    'plays' => (int) ($row->plays ?? 0),
                    'recentCabinets' => $recent,
                    'rankedPlayers' => $ranked,
                    'opinion' => round($opinion, 4),
                    'activity' => round($activity, 4),
                    'index' => round($index, 1),
                    'liked' => self::liked($up, $down, $rules),
                ];
            })
            ->keyBy('gameId');

        $likedRanks = self::ranks($games->where('liked', true)->pluck('activity', 'gameId'));
        $played = $games->where('activity', '>', 0)->pluck('activity', 'gameId');
        // No most played tenth while fewer than ten games are played.
        $playedRanks = $played->count() * $rules['addictive_share'] >= 1 ? self::ranks($played) : collect();

        return $games
            ->map(fn (array $game): GamePopularity => new GamePopularity(
                ...$game,
                label: self::label($game['thumbsUp'], $game['thumbsDown'], $likedRanks->get($game['gameId']), $playedRanks->get($game['gameId']), $rules),
            ))
            ->sortBy([
                fn (GamePopularity $a, GamePopularity $b): int => $b->index <=> $a->index,
                fn (GamePopularity $a, GamePopularity $b): int => $b->plays <=> $a->plays,
                fn (GamePopularity $a, GamePopularity $b): int => $a->gameId <=> $b->gameId,
            ])
            ->keyBy(fn (GamePopularity $popularity): int => $popularity->gameId);
    }

    /**
     * Liked: enough votes, and enough thumbs up among them.
     *
     * @param  array{min_votes: int, liked_share: float, ...}  $rules
     */
    public static function liked(int $thumbsUp, int $thumbsDown, array $rules): bool
    {
        $votes = $thumbsUp + $thumbsDown;

        return $votes >= $rules['min_votes'] && $thumbsUp / $votes >= $rules['liked_share'];
    }

    /**
     * Each game's rank by activity, 0 for the least played to 1 for the most played: the share
     * of the other games played less. Games played as much share the same rank.
     *
     * @param  Collection<int, float>  $activities  Keyed by game id.
     * @return Collection<int, float> Keyed by game id.
     */
    private static function ranks(Collection $activities): Collection
    {
        // A game alone is the most played of its kind.
        if ($activities->count() === 1) {
            return $activities->map(fn (): float => 1.0);
        }
        $others = $activities->count() - 1;

        return $activities->map(fn (float $activity): float => $activities->filter(fn (float $other): bool => $other < $activity)->count() / $others);
    }

    /**
     * At most one label. The votes speak from `min_votes` on. A liked game is a hit or a hidden
     * gem by its rank among the liked games, `$likedRank`; any other game can only be
     * addictive, by its rank among the games played, `$playedRank` (null when too few are).
     *
     * @param  array{min_votes: int, liked_share: float, divisive_margin: float, hit_share: float, hidden_gem_share: float, addictive_share: float, ...}  $rules
     */
    public static function label(int $thumbsUp, int $thumbsDown, ?float $likedRank, ?float $playedRank, array $rules): ?PopularityLabel
    {
        $votes = $thumbsUp + $thumbsDown;

        if ($votes >= $rules['min_votes']) {
            if ($thumbsUp === 0) {
                return PopularityLabel::MissedDate;
            }
            if ($thumbsDown > 0 && abs($thumbsUp - $thumbsDown) / $votes <= $rules['divisive_margin']) {
                return PopularityLabel::Divisive;
            }
        }
        // Rounded: a rank of 2/3 is in the top third, whatever the last bit of 1 - 1/3.
        if ($likedRank !== null) {
            return match (true) {
                $rules['hit_share'] > 0 && round($likedRank, 6) >= round(1 - $rules['hit_share'], 6) => PopularityLabel::Hit,
                $rules['hidden_gem_share'] > 0 && round($likedRank, 6) <= round($rules['hidden_gem_share'], 6) => PopularityLabel::HiddenGem,
                default => null,
            };
        }

        return $playedRank !== null && round($playedRank, 6) >= round(1 - $rules['addictive_share'], 6) ? PopularityLabel::Addictive : null;
    }
}
