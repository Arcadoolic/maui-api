<?php

namespace App\Services\Opinions;

use App\Models\Client;
use App\Models\Game;
use App\Models\GameOpinion;
use Illuminate\Support\Facades\DB;

/**
 * Stores what a cabinet reports of its games: each entry replaces the
 * cabinet's row for that game. Idempotent, and never deletes: a game left
 * out of a report keeps what was known (docs/DECISIONS.md D75).
 */
final class OpinionIntake
{
    /**
     * @param  list<OpinionData>  $opinions
     * @return array{received: int, created: int, updated: int, unchanged: int}
     */
    public function take(Client $client, array $opinions): array
    {
        return DB::transaction(function () use ($client, $opinions): array {
            $result = ['received' => count($opinions), 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            $romnames = array_map(fn (OpinionData $opinion): string => $opinion->romname, $opinions);
            $games = Game::query()->whereIn('romname', $romnames)->get()->keyBy('romname');
            $stored = GameOpinion::query()
                ->where('client_id', $client->id)
                ->whereIn('game_id', $games->modelKeys())
                ->get()
                ->keyBy('game_id');

            foreach ($opinions as $data) {
                // Outside the catalog: a bare game, as a first score makes one (D47).
                $game = $games->get($data->romname)
                    ?? Game::query()->firstOrCreate(['romname' => $data->romname], ['description' => $data->romname]);
                $opinion = $stored->get($game->id) ?? new GameOpinion(['client_id' => $client->id, 'game_id' => $game->id]);

                if ($opinion->vote !== $data->vote || ! $opinion->exists) {
                    $opinion->voted_at = $data->vote === GameOpinion::VOTE_NEUTRAL ? null : now();
                }
                $opinion->fill([
                    'vote' => $data->vote,
                    'play_count' => $data->playCount,
                    'last_played_at' => $data->lastPlayedAt,
                ]);

                if (! $opinion->exists) {
                    $result['created']++;
                } elseif ($opinion->isDirty()) {
                    $result['updated']++;
                } else {
                    $result['unchanged']++;

                    continue;
                }
                $opinion->save();
            }

            return $result;
        });
    }
}
