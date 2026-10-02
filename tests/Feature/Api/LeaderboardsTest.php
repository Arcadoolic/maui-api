<?php

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;

// Shared leaderboards (docs/DECISIONS.md D52).

/**
 * A visible score: public player, active cabinet.
 *
 * @param  array<string, mixed>  $attributes
 */
function leaderboardScore(Game $game, Player $player, int $score, ?Client $client = null, array $attributes = []): Score
{
    return Score::factory()->create([
        'game_id' => $game->id,
        'player_id' => $player->id,
        'client_id' => ($client ?? Client::factory()->create())->id,
        'score' => $score,
        ...$attributes,
    ]);
}

function publicPlayer(string $pseudo3): Player
{
    return Player::factory()->create(['pseudo_3' => $pseudo3, 'is_public' => true]);
}

describe('one game', function () {
    it('ranks the best score of each player, best first', function () {
        [$client, $token] = cabinetWithToken(['name' => 'blue_cabinet']);
        $game = Game::factory()->create(['romname' => 'dkong']);
        $nob = publicPlayer('NOB');
        $ski = publicPlayer('SKI');
        leaderboardScore($game, $nob, 12_000, $client);
        leaderboardScore($game, $nob, 19_200, $client, ['achieved_at' => '2026-10-01T10:00:00Z']);
        leaderboardScore($game, $ski, 15_000, $client);

        $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertExactJson([
                'romname' => 'dkong',
                'table' => 'default',
                'entries' => [
                    ['rank' => 1, 'player' => ['id' => $nob->uuid, 'pseudo_3' => 'NOB'], 'score' => 19_200,
                        'achieved_at' => '2026-10-01T10:00:00+00:00', 'cabinet' => 'blue_cabinet'],
                    ['rank' => 2, 'player' => ['id' => $ski->uuid, 'pseudo_3' => 'SKI'], 'score' => 15_000,
                        'achieved_at' => $ski->scores()->sole()->achieved_at->toIso8601String(), 'cabinet' => 'blue_cabinet'],
                ],
            ]);
    });

    it('keeps the top 9, the earliest first at equal scores', function () {
        [$client, $token] = cabinetWithToken();
        $game = Game::factory()->create(['romname' => 'dkong']);
        $first = publicPlayer('FIR');
        $second = publicPlayer('SEC');
        leaderboardScore($game, $second, 50_000, attributes: ['achieved_at' => '2026-10-02T10:00:00Z']);
        leaderboardScore($game, $first, 50_000, attributes: ['achieved_at' => '2026-10-01T10:00:00Z']);
        foreach (range(1, 10) as $i) {
            leaderboardScore($game, Player::factory()->create(['is_public' => true]), $i * 100);
        }

        $entries = $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))->json('entries');

        expect($entries)->toHaveCount(9)
            ->and(array_slice(array_column(array_column($entries, 'player'), 'pseudo_3'), 0, 2))->toBe(['FIR', 'SEC'])
            ->and(array_column($entries, 'rank'))->toBe(range(1, 9));
    });

    it('leaves out hidden scores, private and disabled players, disabled cabinets', function () {
        [$client, $token] = cabinetWithToken();
        $game = Game::factory()->create(['romname' => 'dkong']);
        $shown = publicPlayer('NOB');
        leaderboardScore($game, $shown, 100);
        leaderboardScore($game, $shown, 900, attributes: ['hidden_at' => now()]);
        leaderboardScore($game, Player::factory()->create(['is_public' => false]), 500);
        leaderboardScore($game, Player::factory()->disabled()->create(['is_public' => true]), 500);
        leaderboardScore($game, publicPlayer('BAN'), 500, Client::factory()->create(['status' => ClientStatus::Disabled]));

        $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.player.pseudo_3', 'NOB')
            ->assertJsonPath('entries.0.score', 100);
    });

    it('brings back the scores of a player who becomes public again', function () {
        [$client, $token] = cabinetWithToken();
        $game = Game::factory()->create(['romname' => 'dkong']);
        $player = Player::factory()->create(['pseudo_3' => 'NOB', 'is_public' => false]);
        leaderboardScore($game, $player, 100);

        $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))->assertJsonCount(0, 'entries');
        $player->forceFill(['is_public' => true])->save();
        $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))->assertJsonCount(1, 'entries');
    });

    it('keeps the tables of a game apart', function () {
        [$client, $token] = cabinetWithToken();
        $game = Game::factory()->create(['romname' => 'dkong']);
        leaderboardScore($game, publicPlayer('NOB'), 100);
        leaderboardScore($game, publicPlayer('SKI'), 200, attributes: ['table' => 'time']);

        $this->getJson('/api/v1/leaderboards/dkong?table=time', cabinetHeaders($client, $token))
            ->assertJsonPath('table', 'time')
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.player.pseudo_3', 'SKI');
    });

    it('answers an empty leaderboard for a game without scores or unknown', function () {
        [$client, $token] = cabinetWithToken();

        $this->getJson('/api/v1/leaderboards/nosuchgame', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertExactJson(['romname' => 'nosuchgame', 'table' => 'default', 'entries' => []]);
    });

    it('answers 304 to an unchanged leaderboard', function () {
        [$client, $token] = cabinetWithToken();
        $game = Game::factory()->create(['romname' => 'dkong']);
        leaderboardScore($game, publicPlayer('NOB'), 100);

        $etag = $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private')
            ->headers->get('ETag');
        expect($etag)->not->toBeNull();

        $this->getJson('/api/v1/leaderboards/dkong', [...cabinetHeaders($client, $token), 'If-None-Match' => $etag])
            ->assertStatus(304);

        leaderboardScore($game, publicPlayer('SKI'), 200);
        $this->getJson('/api/v1/leaderboards/dkong', [...cabinetHeaders($client, $token), 'If-None-Match' => $etag])
            ->assertOk();
    });
});

describe('several games', function () {
    it('answers the leaderboards of the games asked, by romname', function () {
        [$client, $token] = cabinetWithToken();
        leaderboardScore(Game::factory()->create(['romname' => 'galaga']), publicPlayer('NOB'), 100);
        Game::factory()->create(['romname' => 'dkong']);

        $this->getJson('/api/v1/leaderboards?romnames=galaga,dkong,nosuchgame,galaga', cabinetHeaders($client, $token))
            ->assertOk()
            ->assertJsonPath('leaderboards.*.romname', ['dkong', 'galaga', 'nosuchgame'])
            ->assertJsonCount(0, 'leaderboards.0.entries')
            ->assertJsonCount(1, 'leaderboards.1.entries');
    });

    it('validates the romnames, 100 at most', function (string $romnames) {
        [$client, $token] = cabinetWithToken();

        $this->getJson("/api/v1/leaderboards?romnames={$romnames}", cabinetHeaders($client, $token))->assertUnprocessable();
    })->with([
        'missing' => [''],
        'invalid' => ['Dkong!'],
        'too many' => [implode(',', array_map(fn (int $i): string => "game{$i}", range(1, 101)))],
    ]);
});

describe('bests of a player', function () {
    it('lists the visible best of a public player on each game', function () {
        [$client, $token] = cabinetWithToken();
        $player = publicPlayer('NOB');
        $dkong = Game::factory()->create(['romname' => 'dkong']);
        leaderboardScore($dkong, $player, 100);
        leaderboardScore($dkong, $player, 300);
        leaderboardScore($dkong, $player, 900, attributes: ['hidden_at' => now()]);
        leaderboardScore(Game::factory()->create(['romname' => 'galaga']), $player, 50);

        $this->getJson("/api/v1/players/{$player->uuid}/bests", cabinetHeaders($client, $token))
            ->assertOk()
            ->assertJsonPath('player.pseudo_3', 'NOB')
            ->assertJsonPath('bests.*.romname', ['dkong', 'galaga'])
            ->assertJsonPath('bests.*.score', [300, 50]);
    });

    it('hides private and disabled players', function (Closure $make) {
        [$client, $token] = cabinetWithToken();
        $player = $make();

        $this->getJson("/api/v1/players/{$player->uuid}/bests", cabinetHeaders($client, $token))
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    })->with([
        'private' => [fn () => Player::factory()->create(['is_public' => false])],
        'disabled' => [fn () => Player::factory()->disabled()->create(['is_public' => true])],
    ]);
});

it('needs the scores:read ability', function () {
    [$client, $token] = cabinetWithToken(['type' => ClientType::Service]);

    $this->getJson('/api/v1/leaderboards/dkong', cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});
