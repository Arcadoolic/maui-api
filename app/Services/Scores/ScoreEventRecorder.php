<?php

namespace App\Services\Scores;

use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Models\ScoreEvent;
use App\Services\Leaderboards\Leaderboards;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Records what a newly stored score changed on its shared leaderboard
 * (docs/DECISIONS.md D60). Ranks are those of the shared leaderboards: the
 * score of a private player records nothing.
 *
 * @phpstan-import-type Rival from ScoreFacts
 */
final class ScoreEventRecorder
{
    public function __construct(
        private readonly Leaderboards $leaderboards,
        private readonly ScoreSituation $situation,
        private readonly ScoreEventMessage $messages,
    ) {}

    /** The row of a player on a leaderboard: what a new score is compared to. */
    public function baseline(Player $player, Game $game, string $table): ?Score
    {
        return Leaderboards::visibleScores()
            ->where('scores.player_id', $player->id)
            ->where('scores.game_id', $game->id)
            ->where('scores.table', $table)
            ->orderByDesc('scores.score')
            ->orderBy('scores.achieved_at')
            ->with(['player', 'client'])
            ->first();
    }

    /**
     * @param  Score|null  $baseline  The player's row before this score, or before the batch it came in.
     */
    public function record(Score $score, ?Score $baseline): ?ScoreEvent
    {
        $after = $this->leaderboards->board($score->game, $score->table)->values();
        $index = $after->search(fn (Score $row): bool => $row->id === $score->id);
        if (! is_int($index)) {
            // Not on the shared leaderboard: a private player.
            return null;
        }

        // The leaderboard as it was: the other players, and this one's previous row.
        $before = $after->reject(fn (Score $row): bool => $row->id === $score->id)
            ->concat($baseline === null ? [] : [$baseline])
            ->sort(fn (Score $a, Score $b): int => [$b->score, $a->achieved_at, $a->id] <=> [$a->score, $b->achieved_at, $b->id])
            ->values();

        $facts = $this->facts($score, $baseline, $before, $after, $index + 1);
        $displaced = $facts->displaced === null ? null : $before->get($facts->rankAfter - 1);
        $movement = $this->situation->movement($facts);
        $flavors = $this->situation->flavors($facts, $movement);
        $uuid = (string) Str::uuid7();

        $event = new ScoreEvent;
        $event->forceFill([
            'uuid' => $uuid,
            'score_id' => $score->id,
            'player_id' => $score->player_id,
            'game_id' => $score->game_id,
            'table' => $score->table,
            'movement' => $movement,
            'flavors' => $flavors,
            'score' => $score->score,
            'rank_before' => $facts->rankBefore,
            'rank_after' => $facts->rankAfter,
            'displaced_player_id' => $displaced?->player_id,
            'facts' => $facts->toArray(),
            'message' => $this->messages->compose($uuid, $movement, $flavors, $facts, $score->player, $score->game, $score->table),
            'importance' => $this->situation->importance($facts, $movement, $flavors),
            'announceable' => $score->achieved_at->diffInHours(now()) < (int) config('hiscores.events.stale_after_hours'),
            'occurred_at' => $score->achieved_at,
            'recorded_at' => now(),
        ])->save();

        return $event;
    }

    /**
     * @param  Collection<int, Score>  $before
     * @param  Collection<int, Score>  $after
     */
    private function facts(Score $score, ?Score $baseline, Collection $before, Collection $after, int $rankAfter): ScoreFacts
    {
        $player = $score->player;
        $found = $baseline === null ? false : $before->search(fn (Score $row): bool => $row->id === $baseline->id);
        $rankBefore = is_int($found) ? $found + 1 : null;
        $moved = $rankBefore === null || $rankAfter < $rankBefore;
        $becomesLeader = $rankAfter === 1 && $rankBefore !== 1;

        // Whoever held the new rank, unless the player keeps its own.
        $displaced = $moved ? $before->get($rankAfter - 1) : null;
        $ahead = $after->get($rankAfter - 2);
        $leader = $before->first();
        $previousLeader = $leader !== null && $leader->player_id !== $player->id ? $leader : null;

        $events = $this->events($score);
        $own = (clone $events)->where('player_id', $player->id)->orderBy('id')->pluck('rank_after');
        $third = $own->search(3);
        $lastTaker = (clone $events)->where('displaced_player_id', $player->id)->orderByDesc('id')->value('player_id');

        $top = ScoreSituation::PODIUM;
        $size = Leaderboards::SIZE;
        $otherScores = Score::query()->where('player_id', $player->id)->whereKeyNot($score->id);

        return new ScoreFacts(
            score: $score->score,
            previousBest: $baseline?->score,
            rankBefore: $rankBefore,
            rankAfter: $rankAfter,
            boardSize: $after->count(),
            displaced: $displaced === null ? null : self::rival($displaced),
            ahead: $ahead === null ? null : self::rival($ahead),
            previousLeader: $previousLeader === null ? null : self::rival($previousLeader),
            overtaken: $rankBefore === null ? $before->count() - ($rankAfter - 1) : $rankBefore - $rankAfter,
            cabinet: $score->client->name,
            away: $displaced !== null && $displaced->client_id !== $score->client_id,
            pushedOffPodium: ($rankBefore === null || $rankBefore > $top) && $rankAfter <= $top
                ? $before->get($top - 1)?->player->pseudo_3
                : null,
            pushedOutOfBoard: ($rankBefore === null || $rankBefore > $size) && $rankAfter <= $size
                ? $before->get($size - 1)?->player->pseudo_3
                : null,
            heldFirst: $own->contains(1),
            staircase: is_int($third) && $own->slice($third + 1)->contains(2),
            revenge: $displaced !== null && $lastTaker !== null && (int) $lastTaker === $displaced->player_id,
            rounds: $displaced === null ? 0 : 1 + $this->exchanges($events, $player->id, $displaced->player_id, $rankAfter),
            reignDays: $becomesLeader && $previousLeader !== null ? $this->reignDays($events, $previousLeader, $score) : null,
            streak: Score::query()->visible()
                ->where('player_id', $player->id)
                ->where('game_id', $score->game_id)
                ->where('table', $score->table)
                ->whereBetween('achieved_at', [$score->achieved_at->copy()->subDays((int) config('hiscores.events.roll_days')), $score->achieved_at])
                ->count(),
            awayDays: $baseline === null ? null : max(0, (int) $baseline->achieved_at->diffInDays($score->achieved_at)),
            firstScoreEver: (clone $otherScores)->doesntExist(),
            games: Score::query()->visible()->where('player_id', $player->id)->distinct()->count('game_id'),
            newGame: (clone $otherScores)->where('game_id', $score->game_id)->doesntExist(),
            crowns: $becomesLeader ? $this->leaderboards->crowns($player) : null,
            milestone: $this->milestone($score->score, $before->first()?->score),
        );
    }

    /**
     * Earlier events of the leaderboard, those of hidden scores aside.
     *
     * @return Builder<ScoreEvent>
     */
    private function events(Score $score): Builder
    {
        return ScoreEvent::query()
            ->where('game_id', $score->game_id)
            ->where('table', $score->table)
            ->whereNull('retracted_at');
    }

    /**
     * Times one of the two players took this rank from the other.
     *
     * @param  Builder<ScoreEvent>  $events
     */
    private function exchanges(Builder $events, int $playerId, int $rivalId, int $rank): int
    {
        return (clone $events)
            ->where('rank_after', $rank)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $pair) => $pair->where('player_id', $playerId)->where('displaced_player_id', $rivalId))
                ->orWhere(fn (Builder $pair) => $pair->where('player_id', $rivalId)->where('displaced_player_id', $playerId)))
            ->count();
    }

    /**
     * Days the leader held the first place: since the event that put it
     * there, or since its score when it led before events were recorded.
     *
     * @param  Builder<ScoreEvent>  $events
     */
    private function reignDays(Builder $events, Score $leader, Score $score): int
    {
        $since = (clone $events)
            ->where('player_id', $leader->player_id)
            ->where('rank_after', 1)
            ->where(fn (Builder $query) => $query->whereNull('rank_before')->orWhere('rank_before', '>', 1))
            ->orderByDesc('id')
            ->first()->occurred_at ?? $leader->achieved_at;

        return max(0, (int) $since->diffInDays($score->achieved_at));
    }

    /** The highest round score (1 or 5 times a power of ten) this score is the first to reach. */
    private function milestone(int $score, ?int $record): ?int
    {
        if ($record === null) {
            return null;
        }

        $reached = null;
        for ($power = (int) config('hiscores.events.milestone_from'); $power <= $score; $power *= 10) {
            foreach ([$power, $power * 5] as $step) {
                $reached = $step <= $score ? $step : $reached;
            }
        }

        return $reached !== null && $reached > $record ? $reached : null;
    }

    /**
     * @return Rival
     */
    private static function rival(Score $row): array
    {
        return [
            'id' => $row->player->uuid,
            'pseudo_3' => $row->player->pseudo_3,
            'score' => $row->score,
            'cabinet' => $row->client->name,
        ];
    }
}
