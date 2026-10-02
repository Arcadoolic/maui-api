<?php

namespace App\Services\Scores;

use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use Illuminate\Support\Facades\DB;

/**
 * Takes in a batch of scores from a cabinet (docs/DECISIONS.md D50). Each
 * score gets its own outcome, so that one bad score never blocks the rest
 * of the cabinet's outbox:
 * - `accepted`: stored, or already stored under this id (a resend);
 * - `not_improved`: not above the player's best on this game and table,
 *   hidden scores aside; dropped;
 * - `rejected`: with a `code`, the cabinet drops it too.
 * `best` is the player's best after this score, for the cabinet's cache.
 */
final class ScoreIntake
{
    /**
     * @param  list<ScoreData>  $scores
     * @return list<array{id: string, status: string, code?: string, best?: int|null}>
     */
    public function take(Client $client, array $scores): array
    {
        $players = $client->players()
            ->whereIn('players.uuid', array_map(fn (ScoreData $score): string => $score->playerId, $scores))
            ->get()
            ->keyBy('uuid');
        $startupIds = $client->startups()
            ->whereIn('id', array_filter(array_map(fn (ScoreData $score): ?string => $score->startupId, $scores)))
            ->pluck('id')
            ->all();

        return array_map(function (ScoreData $data) use ($client, $players, $startupIds): array {
            $player = $players->get($data->playerId);

            return $player instanceof Player
                ? $this->takeOne($client, $player, $data, in_array($data->startupId, $startupIds, true) ? $data->startupId : null)
                : ['id' => $data->id, 'status' => 'rejected', 'code' => 'player_not_found'];
        }, $scores);
    }

    /**
     * @return array{id: string, status: string, code?: string, best?: int|null}
     */
    private function takeOne(Client $client, Player $player, ScoreData $data, ?string $startupId): array
    {
        return DB::transaction(function () use ($client, $player, $data, $startupId): array {
            // Serializes the scores of one player: two cabinets, or two resends
            // of one score, must not both store a "best".
            Player::query()->whereKey($player->id)->lockForUpdate()->first();
            $existing = Score::query()->where('uuid', $data->id)->first();
            if ($existing !== null) {
                return $existing->client_id === $client->id && $existing->player_id === $player->id
                    ? ['id' => $data->id, 'status' => 'accepted', 'best' => $this->best($player, $existing->game_id, $existing->table)]
                    : ['id' => $data->id, 'status' => 'rejected', 'code' => 'id_conflict'];
            }
            if (! $player->isActive()) {
                return ['id' => $data->id, 'status' => 'rejected', 'code' => 'player_disabled'];
            }

            $game = $this->game($data->romname);
            $best = $this->best($player, $game->id, $data->table);
            if ($best !== null && $data->score <= $best) {
                return ['id' => $data->id, 'status' => 'not_improved', 'best' => $best];
            }

            $score = new Score;
            $score->forceFill([
                'uuid' => $data->id,
                'player_id' => $player->id,
                'game_id' => $game->id,
                'client_id' => $client->id,
                'client_startup_id' => $startupId,
                'table' => $data->table,
                'score' => $data->score,
                'rank_on_cabinet' => $data->rankOnCabinet,
                'achieved_at' => $data->achievedAt,
                'received_at' => now(),
            ])->save();

            return ['id' => $data->id, 'status' => 'accepted', 'best' => $data->score];
        });
    }

    private function best(Player $player, int $gameId, string $table): ?int
    {
        $best = Score::query()->visible()
            ->where('player_id', $player->id)
            ->where('game_id', $gameId)
            ->where('table', $table)
            ->max('score');

        return $best === null ? null : (int) $best;
    }

    /** A game outside the catalog is created bare, as D47 plans for scores. */
    private function game(string $romname): Game
    {
        return Game::query()->firstOrCreate(['romname' => $romname], ['description' => $romname]);
    }
}
