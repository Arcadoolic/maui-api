<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Game;
use App\Models\GameOpinion;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Development only: a made-up fleet of cabinets with votes and plays on the
 * catalogued games, to work on the popularity before real cabinets report
 * (docs/DECISIONS.md D76). The cabinets are named `sim_NN`; `--reset` removes
 * them and their opinions, and nothing else. The same seed gives the same
 * fleet. Refused outside the local and testing environments.
 */
final class SimulateFleetCommand extends Command
{
    public const NAME_PREFIX = 'sim_';

    /**
     * Kinds of games and their share of the catalog; the rest stays untouched.
     * Per kind: share of the cabinets that have the game, chance of a thumbs
     * up and of a thumbs down for a cabinet that has it, plays (min, max),
     * chance that the last play is recent.
     */
    private const PROFILES = [
        'hit' => ['share' => 0.06, 'owned' => 0.9, 'up' => 0.85, 'down' => 0.03, 'plays' => [8, 60], 'recent' => 0.8],
        'kept' => ['share' => 0.08, 'owned' => 0.7, 'up' => 0.8, 'down' => 0.02, 'plays' => [1, 2], 'recent' => 0.05],
        'addictive' => ['share' => 0.05, 'owned' => 0.6, 'up' => 0.15, 'down' => 0.05, 'plays' => [20, 120], 'recent' => 0.9],
        'divisive' => ['share' => 0.05, 'owned' => 0.8, 'up' => 0.45, 'down' => 0.45, 'plays' => [2, 15], 'recent' => 0.4],
        'missed' => ['share' => 0.04, 'owned' => 0.6, 'up' => 0.0, 'down' => 0.9, 'plays' => [1, 2], 'recent' => 0.1],
        'tried' => ['share' => 0.30, 'owned' => 0.4, 'up' => 0.1, 'down' => 0.05, 'plays' => [1, 6], 'recent' => 0.3],
    ];

    protected $signature = 'dev:simulate-fleet
        {--cabinets=12 : Simulated cabinets}
        {--seed=1 : Seed of the random draws}
        {--reset : Only remove the simulated cabinets}';

    protected $description = 'Make up a fleet of cabinets with votes and plays (development only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('dev:simulate-fleet only runs in the local and testing environments.');

            return self::FAILURE;
        }

        $removed = Client::query()->where('name', 'like', self::NAME_PREFIX.'%')->get()->each->delete()->count();
        if ($this->option('reset')) {
            $this->info("{$removed} simulated cabinet(s) removed.");

            return self::SUCCESS;
        }

        $games = Game::query()->whereNotNull('catalogued_at')->orderBy('romname')->pluck('id')->all();
        if ($games === []) {
            $this->error('No catalogued game: push a catalog first (maui-repository, push-catalog).');

            return self::FAILURE;
        }

        // Its own generator: the factories draw from the global one.
        $random = new Randomizer(new Mt19937((int) $this->option('seed')));
        $cabinets = collect(range(1, max(1, (int) $this->option('cabinets'))))
            ->map(fn (int $number): Client => Client::factory()->create([
                'name' => sprintf('%s%02d', self::NAME_PREFIX, $number),
                'owner_name' => 'Simulated fleet',
            ]));

        // Which games are of which kind: drawn once, the same for every cabinet.
        $order = $random->shuffleArray($games);
        $kinds = [];
        $offset = 0;
        foreach (self::PROFILES as $kind => $profile) {
            $count = (int) round(count($games) * $profile['share']);
            foreach (array_slice($order, $offset, $count) as $gameId) {
                $kinds[$gameId] = $kind;
            }
            $offset += $count;
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($cabinets as $cabinet) {
            foreach ($kinds as $gameId => $kind) {
                $profile = self::PROFILES[$kind];
                if (! self::chance($random, $profile['owned'])) {
                    continue;
                }
                $draw = $random->getInt(0, 999_999) / 1_000_000;
                $vote = match (true) {
                    $draw < $profile['up'] => GameOpinion::VOTE_UP,
                    $draw < $profile['up'] + $profile['down'] => GameOpinion::VOTE_DOWN,
                    default => GameOpinion::VOTE_NEUTRAL,
                };
                $lastPlayed = $now->copy()->subDays(self::chance($random, $profile['recent']) ? $random->getInt(0, 25) : $random->getInt(40, 300));
                $rows[] = [
                    'client_id' => $cabinet->id,
                    'game_id' => $gameId,
                    'vote' => $vote,
                    'play_count' => $random->getInt($profile['plays'][0], $profile['plays'][1]),
                    'last_played_at' => $lastPlayed,
                    'voted_at' => $vote === GameOpinion::VOTE_NEUTRAL ? null : $lastPlayed,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            GameOpinion::query()->insert($chunk);
        }

        $counts = array_count_values($kinds);
        $this->info(sprintf(
            '%d simulated cabinet(s), %d opinion(s) on %d of %d games: %s.',
            $cabinets->count(), count($rows), count($kinds), count($games),
            collect($counts)->map(fn (int $count, string $kind): string => "{$count} {$kind}")->implode(', '),
        ));

        return self::SUCCESS;
    }

    private static function chance(Randomizer $random, float $probability): bool
    {
        return $random->getInt(0, 999_999) / 1_000_000 < $probability;
    }
}
