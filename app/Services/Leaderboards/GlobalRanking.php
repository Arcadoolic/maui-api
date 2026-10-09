<?php

namespace App\Services\Leaderboards;

use Illuminate\Support\Collection;

/**
 * The global podium of the hiscores front (docs/DECISIONS.md D70): points
 * for the rank on each game, weighted by how many players are ranked on it,
 * and only the best results of each player counted. Playing many games
 * nobody else plays earns nothing.
 */
final class GlobalRanking
{
    /** The former rule, kept to compare: points of the first three of each game. */
    public const FORMER_POINTS = [1 => 500, 2 => 300, 3 => 50];

    public function __construct(private readonly Rankings $rankings) {}

    /**
     * @return array{base: list<int>, step: int, floor: int, full_competition_players: int, best_results: int}
     */
    public static function rules(): array
    {
        return [
            'base' => array_map(intval(...), array_values((array) config('hiscores.ranking.base'))),
            'step' => (int) config('hiscores.ranking.step'),
            'floor' => (int) config('hiscores.ranking.floor'),
            'full_competition_players' => max(2, (int) config('hiscores.ranking.full_competition_players')),
            'best_results' => max(1, (int) config('hiscores.ranking.best_results')),
        ];
    }

    /**
     * Points of a rank on a leaderboard of `$players` ranked players.
     *
     * @param  array{base: list<int>, step: int, floor: int, full_competition_players: int, best_results: int}|null  $rules
     */
    public static function points(int $rank, int $players, ?array $rules = null): int
    {
        $rules ??= self::rules();
        $base = $rules['base'][$rank - 1]
            ?? max($rules['floor'], (int) end($rules['base']) - $rules['step'] * ($rank - count($rules['base'])));
        $competition = min(1, max(0, $players - 1) / ($rules['full_competition_players'] - 1));

        return (int) round($base * $competition);
    }

    /**
     * Every ranked player, first to last.
     *
     * @param  array{base: list<int>, step: int, floor: int, full_competition_players: int, best_results: int}|null  $rules
     * @return Collection<int, Standing>
     */
    public function standings(?array $rules = null): Collection
    {
        $rules ??= self::rules();
        $rows = $this->rankings->rows()->get(['player_id', 'game_id', 'table', 'rank', 'players', 'achieved_at']);

        $entries = [];
        foreach ($rows->groupBy('player_id') as $playerId => $boards) {
            $results = [];
            foreach ($boards as $row) {
                $results[] = [
                    'game_id' => (int) $row->game_id,
                    'table' => (string) $row->table,
                    'rank' => (int) $row->rank,
                    'players' => (int) $row->players,
                    'points' => self::points((int) $row->rank, (int) $row->players, $rules),
                ];
            }
            usort($results, fn (array $a, array $b): int => [$b['points'], $a['rank'], $a['game_id'], $a['table']] <=> [$a['points'], $b['rank'], $b['game_id'], $b['table']]);
            // One result per game: its best table, so that a game with several tables does not count twice.
            $perGame = [];
            foreach ($results as $result) {
                $perGame[$result['game_id']] ??= $result;
            }
            $counted = [];
            foreach (array_values($perGame) as $index => $result) {
                $counted[] = [...$result, 'counted' => $index < $rules['best_results'] && $result['points'] > 0];
            }

            $entries[] = [
                'player_id' => (int) $playerId,
                'points' => array_sum(array_map(fn (array $result): int => $result['counted'] ? $result['points'] : 0, $counted)),
                'former_points' => (int) $boards->sum(fn (object $row): int => self::FORMER_POINTS[(int) $row->rank] ?? 0),
                'crowns' => $boards->where('rank', 1)->count(),
                'podiums' => $boards->where('rank', '<=', 3)->count(),
                'oldest_best' => (string) $boards->min('achieved_at'),
                'results' => $counted,
            ];
        }
        // Ties: crowns, then podiums, then whoever has been there longest.
        usort($entries, fn (array $a, array $b): int => [$b['points'], $b['crowns'], $b['podiums'], $a['oldest_best'], $a['player_id']]
            <=> [$a['points'], $a['crowns'], $a['podiums'], $b['oldest_best'], $b['player_id']]);

        $standings = [];
        foreach ($entries as $index => $entry) {
            $standings[] = new Standing(
                $entry['player_id'], $index + 1, $entry['points'], $entry['former_points'],
                $entry['crowns'], $entry['podiums'], count($entry['results']), $entry['results'],
            );
        }

        return collect($standings);
    }
}
