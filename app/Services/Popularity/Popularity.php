<?php

namespace App\Services\Popularity;

use App\Enums\PopularityLabel;
use App\Models\GameOpinion;
use App\Services\Leaderboards\Rankings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Popularity of the games, from what the cabinets report (D75) and the
 * visible scores (docs/DECISIONS.md D76).
 *
 * - Opinion: thumbs up among the votes, as a Bayesian average: `prior_votes`
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
 */
final class Popularity
{
    public function __construct(private readonly Rankings $rankings) {}

    /**
     * @return array{opinion_weight: float, activity_weight: float, prior_votes: float, min_votes: int, liked_share: float, divisive_margin: float, active_from: float, activity_half: float, recent_days: int, plays_weight: float, recent_weight: float, players_weight: float}
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
            'active_from' => min(1.0, max(0.0, $number('active_from'))),
            'activity_half' => max(0.01, $number('activity_half')),
            'recent_days' => max(1, (int) $number('recent_days')),
            'plays_weight' => max(0.0, $number('plays_weight')),
            'recent_weight' => max(0.0, $number('recent_weight')),
            'players_weight' => max(0.0, $number('players_weight')),
        ];
    }

    /**
     * Every game a cabinet voted on or played, or with a visible score,
     * the most popular first.
     *
     * @param  array{opinion_weight: float, activity_weight: float, prior_votes: float, min_votes: int, liked_share: float, divisive_margin: float, active_from: float, activity_half: float, recent_days: int, plays_weight: float, recent_weight: float, players_weight: float}|null  $rules
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
        $players = $this->rankings->gameTotals()->pluck('ranked_players', 'game_id');

        $thumbsUp = (int) $opinions->sum('thumbs_up');
        $votes = $thumbsUp + (int) $opinions->sum('thumbs_down');
        // Nobody voted yet: neither liked nor disliked.
        $fleetShare = $votes > 0 ? $thumbsUp / $votes : 0.5;
        $weights = $rules['opinion_weight'] + $rules['activity_weight'];
        // Cabinets that reported anything: what "played by the whole fleet" is measured against.
        $fleet = max(1, (int) DB::table('game_opinions')->distinct()->count('client_id'));

        return $opinions->keys()->merge($players->keys())->unique()
            ->map(function (int|string $gameId) use ($opinions, $players, $rules, $fleetShare, $weights, $fleet): GamePopularity {
                $row = $opinions->get($gameId);
                $up = (int) ($row->thumbs_up ?? 0);
                $down = (int) ($row->thumbs_down ?? 0);
                $recent = (int) ($row->recent_cabinets ?? 0);
                $ranked = (int) ($players->get($gameId) ?? 0);

                $opinion = ($up + $rules['prior_votes'] * $fleetShare) / max($up + $down + $rules['prior_votes'], PHP_FLOAT_EPSILON);
                $raw = ($rules['plays_weight'] * (float) ($row->damped_plays ?? 0) + $rules['recent_weight'] * $recent) / $fleet
                    + $rules['players_weight'] * log(1 + $ranked);
                $activity = $raw / ($raw + $rules['activity_half']);
                $index = $weights > 0
                    ? 100 * ($rules['opinion_weight'] * $opinion + $rules['activity_weight'] * $activity) / $weights
                    : 0.0;

                return new GamePopularity(
                    gameId: (int) $gameId,
                    thumbsUp: $up,
                    thumbsDown: $down,
                    cabinets: (int) ($row->cabinets ?? 0),
                    plays: (int) ($row->plays ?? 0),
                    recentCabinets: $recent,
                    rankedPlayers: $ranked,
                    opinion: round($opinion, 4),
                    activity: round($activity, 4),
                    index: round($index, 1),
                    label: self::label($up, $down, $activity, $rules),
                );
            })
            ->sortBy([
                fn (GamePopularity $a, GamePopularity $b): int => $b->index <=> $a->index,
                fn (GamePopularity $a, GamePopularity $b): int => $b->plays <=> $a->plays,
                fn (GamePopularity $a, GamePopularity $b): int => $a->gameId <=> $b->gameId,
            ])
            ->keyBy(fn (GamePopularity $popularity): int => $popularity->gameId);
    }

    /**
     * At most one label. The votes speak from `min_votes` on: under it, a
     * game can only be `addictive`, which the plays alone decide.
     *
     * @param  array{min_votes: int, liked_share: float, divisive_margin: float, active_from: float, ...}  $rules
     */
    public static function label(int $thumbsUp, int $thumbsDown, float $activity, array $rules): ?PopularityLabel
    {
        $votes = $thumbsUp + $thumbsDown;
        $active = $activity >= $rules['active_from'];

        if ($votes >= $rules['min_votes']) {
            if ($thumbsUp === 0) {
                return PopularityLabel::MissedDate;
            }
            if ($thumbsDown > 0 && abs($thumbsUp - $thumbsDown) / $votes <= $rules['divisive_margin']) {
                return PopularityLabel::Divisive;
            }
            if ($thumbsUp / $votes >= $rules['liked_share']) {
                return $active ? PopularityLabel::Hit : PopularityLabel::HiddenGem;
            }
        }

        return $active ? PopularityLabel::Addictive : null;
    }
}
