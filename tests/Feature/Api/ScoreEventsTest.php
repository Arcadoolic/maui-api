<?php

use App\Models\Client;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Models\ScoreEvent;
use App\Services\Scores\ScoreEventMessage;
use App\Services\Scores\ScoreFacts;
use App\Services\Scores\ScoreModeration;
use Illuminate\Support\Str;

// What a stored score changed on its leaderboard (docs/DECISIONS.md D60).

function galaga(): Game
{
    return Game::query()->firstWhere('romname', 'galaga')
        ?? Game::factory()->create(['romname' => 'galaga', 'description' => 'Galaga (Rev. 1)', 'manufacturer' => 'Namco']);
}

/**
 * A cabinet and its token, with public players linked to it.
 *
 * @return array{0: Client, 1: string}
 */
function arcade(): array
{
    galaga();

    return cabinetWithToken();
}

function challenger(Client $client, string $pseudo3): Player
{
    return linkedPlayer($client, ['pseudo_3' => $pseudo3, 'is_public' => true]);
}

/**
 * A score sent by the cabinet, as MAUI does: the event it records, if any.
 *
 * @param  array{0: Client, 1: string}  $arcade
 * @param  array<string, mixed>  $overrides
 */
function play(array $arcade, Player $player, int $score, array $overrides = []): ?ScoreEvent
{
    $payload = [
        'id' => (string) Str::uuid(),
        'player_id' => $player->uuid,
        'romname' => 'galaga',
        'score' => $score,
        'achieved_at' => now()->toIso8601String(),
        ...$overrides,
    ];
    test()->postJson('/api/v1/scores', ['scores' => [$payload]], cabinetHeaders(...$arcade))
        ->assertOk()
        ->assertJsonPath('results.0.status', 'accepted');

    return ScoreEvent::query()->whereRelation('player', 'id', $player->id)->orderByDesc('id')->first()
        ?->score_id === Score::query()->where('uuid', $payload['id'])->value('id')
            ? ScoreEvent::query()->orderByDesc('id')->first()
            : null;
}

/** Scores already on the leaderboard, without events: as before D60. */
function seedBoard(int ...$scores): void
{
    foreach ($scores as $index => $score) {
        leaderboardScore(galaga(), publicPlayer('P'.chr(65 + $index).'X'), $score, attributes: ['achieved_at' => now()->subDays(2)]);
    }
}

describe('movements', function () {
    it('opens the board with the first score of a game', function () {
        $arcade = arcade();

        $event = play($arcade, challenger($arcade[0], 'LOY'), 78_340);

        expect($event->movement)->toBe('opens_board')
            ->and($event->rank_before)->toBeNull()
            ->and($event->rank_after)->toBe(1)
            ->and($event->score)->toBe(78_340)
            ->and($event->importance)->toBe(3)
            ->and($event->announceable)->toBeTrue()
            ->and($event->message)->toContain('**LOY**', '**Galaga (Rev. 1)** by Namco', '**78,340**');
    });

    it('tells a first score outside the podium as a debut', function () {
        $arcade = arcade();
        seedBoard(500, 400, 300, 200);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 100);

        expect($event->movement)->toBe('debut')
            ->and($event->rank_after)->toBe(5)
            ->and($event->facts['board_size'])->toBe(5)
            ->and($event->facts['displaced'])->toBeNull()
            ->and($event->message)->toContain('5th place');
    });

    it('tells a first score on the podium', function () {
        $arcade = arcade();
        seedBoard(500, 400, 300, 200);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 350);

        expect($event->movement)->toBe('debut_podium')
            ->and($event->rank_after)->toBe(3)
            ->and($event->facts['pushed_off_podium'])->toBe('PCX')
            ->and($event->message)->toContain('3rd place', '**PCX** drops off the podium.');
    });

    it('tells no podium on a board of three players or less', function () {
        $arcade = arcade();
        seedBoard(500, 400);

        expect(play($arcade, challenger($arcade[0], 'LOY'), 450)->movement)->toBe('debut');
    });

    it('tells a first score that takes the first place', function () {
        $arcade = arcade();
        seedBoard(500, 400);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 600);

        expect($event->movement)->toBe('debut_first')
            ->and($event->facts['displaced']['pseudo_3'])->toBe('PAX')
            ->and($event->displaced_player_id)->toBe(Player::query()->firstWhere('pseudo_3', 'PAX')->id)
            ->and($event->message)->toContain('**PAX**');
    });

    it('tells a player who takes the first place, then one who takes it back', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $nob = challenger($arcade[0], 'NOB');
        play($arcade, $nob, 500);
        play($arcade, $loy, 400);

        $taken = play($arcade, $loy, 600);
        play($arcade, $nob, 700);
        $reclaimed = play($arcade, $loy, 800);

        expect($taken->movement)->toBe('takes_first')
            ->and($taken->rank_before)->toBe(2)
            ->and($taken->facts['previous_best'])->toBe(400)
            ->and($taken->facts['displaced']['pseudo_3'])->toBe('NOB')
            ->and($reclaimed->movement)->toBe('reclaims_first')
            ->and($reclaimed->facts['held_first'])->toBeTrue();
    });

    it('tells a leader who improves the record', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        seedBoard(300);
        play($arcade, $loy, 500);

        $event = play($arcade, $loy, 600);

        expect($event->movement)->toBe('extends_lead')
            ->and($event->rank_before)->toBe(1)
            ->and($event->facts['displaced'])->toBeNull()
            ->and($event->facts['previous_leader'])->toBeNull();
    });

    it('tells a player who climbs onto the podium', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        seedBoard(500, 400, 300, 200);
        play($arcade, $loy, 100);

        $event = play($arcade, $loy, 450);

        expect($event->movement)->toBe('enters_podium')
            ->and($event->rank_before)->toBe(5)
            ->and($event->rank_after)->toBe(2)
            ->and($event->facts['displaced']['pseudo_3'])->toBe('PBX')
            ->and($event->facts['pushed_off_podium'])->toBe('PCX')
            ->and($event->facts['overtaken'])->toBe(3);
    });

    it('tells a player who takes a place outside the podium', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        seedBoard(500, 400, 300, 200, 150);
        play($arcade, $loy, 100);

        $event = play($arcade, $loy, 160);

        expect($event->movement)->toBe('climbs')
            ->and($event->rank_after)->toBe(5)
            ->and($event->facts['displaced']['pseudo_3'])->toBe('PEX')
            ->and($event->importance)->toBe(2)
            ->and($event->message)->toContain('5th place', '**PEX**');
    });

    it('tells a better score that keeps its rank, with the gap to the next player', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        seedBoard(500, 400, 300, 200);
        play($arcade, $loy, 100);

        $event = play($arcade, $loy, 150);

        expect($event->movement)->toBe('improves')
            ->and($event->rank_before)->toBe(5)
            ->and($event->rank_after)->toBe(5)
            ->and($event->facts['ahead']['pseudo_3'])->toBe('PDX')
            ->and($event->facts['gap'])->toBe(50)
            ->and($event->message)->toContain('**50**', '**PDX**', 'still 5th');
    });

    it('takes a player out of the rows a cabinet shows', function () {
        $arcade = arcade();
        seedBoard(900, 800, 700, 600, 500, 400, 300, 200, 100);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 450);

        expect($event->facts['pushed_out_of_board'])->toBe('PIX')
            ->and($event->message)->toContain('**PIX** is out of the top 9.');
    });
});

describe('flavors', function () {
    it('greets the very first score of a player', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');

        expect(play($arcade, $loy, 100)->flavors)->toContain('newcomer')
            ->and(play($arcade, $loy, 150)->flavors)->not->toContain('newcomer');
    });

    it('notes a score at least twice the previous best', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        play($arcade, $loy, 50_000);

        expect(play($arcade, $loy, 99_999)->flavors)->not->toContain('huge_jump')
            ->and(play($arcade, $loy, 200_000)->flavors)->toContain('huge_jump');
    });

    it('notes three bests on a game within a week', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        play($arcade, $loy, 100, ['achieved_at' => now()->subDays(10)->toIso8601String()]);
        play($arcade, $loy, 110, ['achieved_at' => now()->subDays(3)->toIso8601String()]);

        expect(play($arcade, $loy, 120)->flavors)->not->toContain('on_a_roll');

        $event = play($arcade, $loy, 130);
        expect($event->flavors)->toContain('on_a_roll')
            ->and($event->facts['streak'])->toBe(3)
            ->and(play($arcade, $loy, 140)->flavors)->not->toContain('on_a_roll');
    });

    it('notes a first place reached through the third and the second', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        seedBoard(500, 400, 300);
        play($arcade, $loy, 350);
        play($arcade, $loy, 450);

        $event = play($arcade, $loy, 550);

        expect($event->movement)->toBe('takes_first')
            ->and($event->flavors[0])->toBe('staircase');
    });

    it('notes a place taken back from who took it, then the duel going on', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $nob = challenger($arcade[0], 'NOB');
        play($arcade, $nob, 100);
        $first = play($arcade, $loy, 200);
        $second = play($arcade, $nob, 300);
        $third = play($arcade, $loy, 400);

        expect($first->flavors)->not->toContain('revenge')
            ->and($second->flavors)->toContain('revenge')->not->toContain('rivalry')
            ->and($third->flavors)->toContain('revenge')
            ->and($third->flavors[0])->toBe('rivalry')
            ->and($third->facts['rounds'])->toBe(3)
            ->and($third->message)->toContain('round 3');
    });

    it('notes the end of a long reign', function () {
        $arcade = arcade();
        leaderboardScore(galaga(), publicPlayer('NOB'), 500, attributes: ['achieved_at' => now()->subDays(42)]);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 600);

        expect($event->flavors)->toContain('reign_ended')
            ->and($event->facts['reign_days'])->toBe(42);
    });

    it('counts a reign from the event that started it', function () {
        $arcade = arcade();
        $nob = challenger($arcade[0], 'NOB');
        seedBoard(100);
        play($arcade, $nob, 500, ['achieved_at' => now()->subDays(40)->toIso8601String()]);
        play($arcade, $nob, 550, ['achieved_at' => now()->subDays(5)->toIso8601String()]);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 600);

        expect($event->facts['reign_days'])->toBe(40);
    });

    it('notes a place taken by less than one percent', function () {
        $arcade = arcade();
        seedBoard(100_000, 50_000);

        $event = play($arcade, challenger($arcade[0], 'LOY'), 50_120);

        expect($event->flavors)->toContain('photo_finish')
            ->and($event->message)->toContain('By just 120 points!');
    });

    it('notes a leader beaten by half as much again', function () {
        $arcade = arcade();
        seedBoard(100_000);

        expect(play($arcade, challenger($arcade[0], 'LOY'), 149_999)->flavors)->not->toContain('crushing')
            ->and(play($arcade, challenger($arcade[0], 'NOB'), 250_000)->flavors)->toContain('crushing');
    });

    it('notes three players overtaken at once', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        seedBoard(900, 800, 700, 600, 500, 400);
        play($arcade, $loy, 100);

        $event = play($arcade, $loy, 190);
        expect($event->flavors)->not->toContain('leapfrog');

        $event = play($arcade, $loy, 650);
        expect($event->flavors)->toContain('leapfrog')
            ->and($event->facts['overtaken'])->toBe(3);
    });

    it('notes a best after a long time away', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        play($arcade, $loy, 100, ['achieved_at' => now()->subDays(95)->toIso8601String()]);

        $event = play($arcade, $loy, 150);

        expect($event->flavors)->toContain('comeback')
            ->and($event->facts['away_days'])->toBe(95)
            ->and($event->message)->toContain('Back after 3 months away.');
    });

    it('notes the first score past a round number, not on an empty board', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');

        expect(play($arcade, $loy, 120_000)->facts['milestone'])->toBeNull()
            ->and(play($arcade, $loy, 180_000)->facts['milestone'])->toBeNull();

        $event = play($arcade, challenger($arcade[0], 'NOB'), 1_200_000);
        expect($event->flavors)->toContain('milestone')
            ->and($event->facts['milestone'])->toBe(1_000_000);
    });

    it('notes a third leaderboard led', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        play($arcade, $loy, 100, ['romname' => 'dkong']);
        play($arcade, $loy, 100, ['romname' => 'pacman']);

        $event = play($arcade, $loy, 100);

        expect($event->flavors)->toContain('multi_crown')
            ->and($event->facts['crowns'])->toBe(3);
    });

    it('notes a tenth game with a score', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        foreach (range(1, 9) as $game) {
            play($arcade, $loy, 100, ['romname' => 'game'.$game]);
        }

        $event = play($arcade, $loy, 100);

        expect($event->flavors)->toContain('collector')
            ->and($event->facts['games'])->toBe(10)
            ->and(play($arcade, $loy, 200)->flavors)->not->toContain('collector');
    });

    it('notes a place taken from another cabinet', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $nob = challenger($arcade[0], 'NOB');
        seedBoard(500);
        play($arcade, $nob, 100);

        expect(play($arcade, $loy, 200)->flavors)->not->toContain('away_win')
            ->and(play($arcade, $loy, 600)->flavors)->toContain('away_win');
    });
});

describe('what is recorded', function () {
    it('records nothing for a private player', function () {
        $arcade = arcade();

        expect(play($arcade, linkedPlayer($arcade[0], ['is_public' => false]), 100))->toBeNull()
            ->and(ScoreEvent::query()->count())->toBe(0);
    });

    it('ranks among the public players only', function () {
        $arcade = arcade();
        play($arcade, linkedPlayer($arcade[0], ['is_public' => false]), 900);

        expect(play($arcade, challenger($arcade[0], 'LOY'), 100)->movement)->toBe('opens_board');
    });

    it('records nothing for a score that is not a best, nor for a resend', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $payload = ['id' => (string) Str::uuid(), 'player_id' => $loy->uuid, 'romname' => 'galaga', 'score' => 500, 'achieved_at' => now()->toIso8601String()];

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/scores', ['scores' => [$payload]], cabinetHeaders(...$arcade))->assertOk();
        }
        $this->postJson('/api/v1/scores', ['scores' => [[...$payload, 'id' => (string) Str::uuid(), 'score' => 400]]], cabinetHeaders(...$arcade))
            ->assertJsonPath('results.0.status', 'not_improved');

        expect(ScoreEvent::query()->count())->toBe(1);
    });

    it('stores a score made long ago without announcing it', function () {
        $this->freezeSecond();
        $arcade = arcade();

        $event = play($arcade, challenger($arcade[0], 'LOY'), 100, ['achieved_at' => now()->subHours(25)->toIso8601String()]);

        expect($event->announceable)->toBeFalse()
            ->and($event->occurred_at->toIso8601String())->toBe(now()->subHours(25)->toIso8601String());
    });

    it('records one event for the bests of a player sent in one batch, against the board before it', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $nob = challenger($arcade[0], 'NOB');
        seedBoard(500, 400, 300);
        play($arcade, $loy, 100);
        $score = fn (Player $player, int $score): array => [
            'id' => (string) Str::uuid(), 'player_id' => $player->uuid, 'romname' => 'galaga', 'score' => $score, 'achieved_at' => now()->toIso8601String(),
        ];

        $this->postJson('/api/v1/scores', ['scores' => [
            $score($loy, 350), $score($nob, 450), $score($loy, 600), $score($loy, 550),
        ]], cabinetHeaders(...$arcade))->assertOk();

        $events = ScoreEvent::query()->orderBy('id')->get()->slice(1)->values();
        expect(Score::query()->where('player_id', $loy->id)->count())->toBe(3)
            ->and($events)->toHaveCount(2)
            ->and($events[0]->player_id)->toBe($nob->id)
            ->and($events[1]->player_id)->toBe($loy->id)
            ->and($events[1]->score)->toBe(600)
            ->and($events[1]->movement)->toBe('takes_first')
            ->and($events[1]->rank_before)->toBe(5)
            ->and($events[1]->facts['previous_best'])->toBe(100);
    });

    it('retracts the event of a hidden score, and brings it back with it', function () {
        $arcade = arcade();
        $event = play($arcade, challenger($arcade[0], 'LOY'), 100);
        $score = Score::query()->sole();

        app(ScoreModeration::class)->hide($score);
        expect($event->refresh()->retracted_at)->not->toBeNull();

        app(ScoreModeration::class)->unhide($score);
        expect($event->refresh()->retracted_at)->toBeNull();
    });

    it('names a game outside the catalog, and a table, as they are', function () {
        $arcade = arcade();

        $event = play($arcade, challenger($arcade[0], 'LOY'), 100, ['romname' => 'new_game', 'table' => 'level_2']);

        expect($event->table)->toBe('level_2')
            ->and($event->message)->toContain('**new\_game** (table level\_2)')
            ->not->toContain(' by ');
    });
});

it('writes every movement and flavor without a placeholder left', function (string $movement, string $flavor) {
    $rival = ['id' => (string) Str::uuid(), 'pseudo_3' => 'NOB', 'score' => 900, 'cabinet' => 'blue_cabinet'];
    $facts = new ScoreFacts(
        score: 1_000, previousBest: 400, rankBefore: 5, rankAfter: 2, boardSize: 8, displaced: $rival, ahead: $rival,
        previousLeader: $rival, overtaken: 3, cabinet: 'red_cabinet', away: true, pushedOffPodium: 'DID', pushedOutOfBoard: null,
        heldFirst: true, staircase: true, revenge: true, rounds: 3, reignDays: 42, streak: 3, awayDays: 95,
        firstScoreEver: false, games: 10, newGame: true, crowns: 3, milestone: 1_000,
    );
    $player = Player::factory()->make(['pseudo_3' => 'LOY']);
    $game = Game::factory()->make(['description' => 'Galaga (Rev. 1)', 'manufacturer' => 'Namco']);

    foreach (range(1, 40) as $seed) {
        $message = app(ScoreEventMessage::class)->compose((string) $seed, $movement, [$flavor], $facts, $player, $game, 'default');

        expect($message)->not->toMatch('/:[a-z]/')->toContain('**LOY**', '**Galaga (Rev. 1)** by Namco', '**1,000**');
    }
})->with(array_keys(ScoreEventMessage::MOVEMENTS))->with(array_keys(ScoreEventMessage::FLAVORS));

describe('bot feed', function () {
    it('lists the events after a cursor, oldest first', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $first = play($arcade, $loy, 100, ['achieved_at' => '2026-10-08T10:00:00Z']);
        $second = play($arcade, $loy, 200);
        $third = play($arcade, $loy, 300);
        $headers = serviceHeaders(...botWithToken());

        $this->getJson('/api/v1/bot/events?after='.$first->id, $headers)
            ->assertOk()
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.id', $second->id)
            ->assertJsonPath('events.1.id', $third->id)
            ->assertJsonPath('events.1.uuid', $third->uuid)
            ->assertJsonPath('events.1.movement', 'extends_lead')
            ->assertJsonPath('events.1.message', $third->message)
            ->assertJsonPath('events.1.player', ['id' => $loy->uuid, 'pseudo_3' => 'LOY', 'avatar' => null])
            ->assertJsonPath('events.1.game', ['romname' => 'galaga', 'description' => 'Galaga (Rev. 1)', 'manufacturer' => 'Namco'])
            ->assertJsonPath('events.1.table', 'default')
            ->assertJsonPath('events.1.score', 300)
            ->assertJsonPath('events.1.rank_before', 1)
            ->assertJsonPath('events.1.rank_after', 1)
            ->assertJsonPath('events.1.facts.previous_best', 200)
            ->assertJsonPath('cursor', $third->id);

        $this->getJson('/api/v1/bot/events?after='.$first->id.'&limit=1', $headers)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('cursor', $second->id);
    });

    it('answers the latest events without a cursor, and the cursor to go on from', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $headers = serviceHeaders(...botWithToken());

        $this->getJson('/api/v1/bot/events', $headers)->assertOk()->assertExactJson(['events' => [], 'cursor' => 0]);

        play($arcade, $loy, 100);
        $second = play($arcade, $loy, 200);
        $stale = play($arcade, challenger($arcade[0], 'NOB'), 50, ['achieved_at' => now()->subDays(3)->toIso8601String()]);

        $this->getJson('/api/v1/bot/events?limit=1', $headers)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $second->id)
            ->assertJsonPath('cursor', $second->id);
        $this->getJson('/api/v1/bot/events?after='.$second->id, $headers)
            ->assertExactJson(['events' => [], 'cursor' => $second->id]);
        expect($stale->id)->toBeGreaterThan($second->id);
    });

    it('leaves out what must not be announced', function () {
        $arcade = arcade();
        $loy = challenger($arcade[0], 'LOY');
        $nob = challenger($arcade[0], 'NOB');
        $did = challenger($arcade[0], 'DID');
        play($arcade, $loy, 100, ['achieved_at' => now()->subDays(2)->toIso8601String()]);
        play($arcade, $nob, 200);
        play($arcade, $did, 300);
        $kept = play($arcade, $loy, 400);
        app(ScoreModeration::class)->hide(Score::query()->where('player_id', $nob->id)->sole());
        $did->forceFill(['is_public' => false])->save();

        $this->getJson('/api/v1/bot/events?after=0', serviceHeaders(...botWithToken()))
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $kept->id);
    });

    it('validates the cursor and the limit', function (string $query) {
        $this->getJson('/api/v1/bot/events?'.$query, serviceHeaders(...botWithToken()))
            ->assertUnprocessable();
    })->with(['after=-1', 'after=abc', 'limit=0', 'limit=101']);

    it('needs a bot account', function (Closure $headers) {
        $this->getJson('/api/v1/bot/events', $headers())
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_ability');
    })->with([
        'catalog service account' => [fn () => serviceHeaders(...serviceWithToken())],
        'cabinet' => [fn () => cabinetHeaders(...cabinetWithToken())],
    ]);

    it('refuses an anonymous request', function () {
        $this->getJson('/api/v1/bot/events')->assertUnauthorized();
    });
});
