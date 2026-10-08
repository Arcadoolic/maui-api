<?php

namespace App\Services\Leaderboards;

use App\Enums\ClientStatus;
use App\Enums\PlayerStatus;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared leaderboards (docs/DECISIONS.md D52): the best score of each player
 * on a game, among the scores that are not hidden, of a public and active
 * player, sent by an active cabinet. A private player's scores come back as
 * soon as it is public again (D48).
 */
final class Leaderboards
{
    /** Rows of a leaderboard: what a cabinet's hiscore screen shows. */
    public const SIZE = 9;

    /**
     * Scores that may appear in a leaderboard.
     *
     * @return Builder<Score>
     */
    public static function visibleScores(): Builder
    {
        return Score::query()
            ->whereNull('scores.hidden_at')
            ->whereHas('player', fn (Builder $query) => $query
                ->where('is_public', true)
                ->where('status', PlayerStatus::Active))
            ->whereHas('client', fn (Builder $query) => $query->where('status', ClientStatus::Active));
    }

    /**
     * The best score of each player on the game and table, best first; at
     * equal scores, the one made first.
     *
     * @return Collection<int, Score>
     */
    public function top(Game $game, string $table = Score::DEFAULT_TABLE, int $limit = self::SIZE): Collection
    {
        $bestPerPlayer = self::visibleScores()
            ->where('scores.game_id', $game->id)
            ->where('scores.table', $table)
            ->select('scores.*')
            ->distinct('scores.player_id')
            ->orderBy('scores.player_id')
            ->orderByDesc('scores.score')
            ->orderBy('scores.achieved_at');

        return Score::query()
            ->fromSub($bestPerPlayer, 'scores')
            ->orderByDesc('score')
            ->orderBy('achieved_at')
            ->orderBy('id')
            ->limit($limit)
            ->with(['player', 'client'])
            ->get();
    }

    /**
     * The visible best of a player on each game and table, by game.
     *
     * @return Collection<int, Score>
     */
    public function bests(Player $player): Collection
    {
        $bestPerGame = self::visibleScores()
            ->where('scores.player_id', $player->id)
            ->select('scores.*')
            ->distinct(['scores.game_id', 'scores.table'])
            ->orderBy('scores.game_id')
            ->orderBy('scores.table')
            ->orderByDesc('scores.score');

        return Score::query()
            ->fromSub($bestPerGame, 'scores')
            ->with('game')
            ->get()
            ->sortBy(fn (Score $score): string => $score->game->romname.'/'.$score->table)
            ->values();
    }

    /**
     * Each game and table with at least one visible score, by game
     * description: what a bot offers to choose from (D57).
     *
     * @return list<array{romname: string, description: string, table: string}>
     */
    public function available(): array
    {
        return array_values(self::visibleScores()
            ->join('games', 'games.id', '=', 'scores.game_id')
            ->distinct()
            ->orderBy('games.description')
            ->orderBy('games.romname')
            ->orderBy('scores.table')
            ->toBase()
            ->get(['games.romname', 'games.description', 'scores.table'])
            ->map(fn (object $row): array => [
                'romname' => (string) $row->romname,
                'description' => (string) $row->description,
                'table' => (string) $row->table,
            ])
            ->all());
    }

    /**
     * A leaderboard as the API sends it.
     *
     * @return array{romname: string, table: string, entries: list<array<string, mixed>>}
     */
    public function toApiArray(string $romname, ?Game $game, string $table = Score::DEFAULT_TABLE): array
    {
        $entries = $game === null ? [] : array_values($this->top($game, $table)->values()->map(fn (Score $score, int $index): array => [
            'rank' => $index + 1,
            'player' => [
                'id' => $score->player->uuid,
                'pseudo_3' => $score->player->pseudo_3,
                // Hash of the avatar (D53): download it again only when it changes.
                'avatar' => $score->player->avatar_hash,
            ],
            'score' => $score->score,
            'achieved_at' => $score->achieved_at->toIso8601String(),
            'cabinet' => $score->client->name,
        ])->all());

        return ['romname' => $romname, 'table' => $table, 'entries' => $entries];
    }
}
