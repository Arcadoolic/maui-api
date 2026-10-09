<?php

use App\Enums\CategorySource;
use App\Enums\ScoreAttribution;
use App\Models\Category;
use App\Models\Client;
use App\Models\Game;
use App\Models\GameMedia;
use App\Models\Member;
use App\Models\Player;
use App\Models\ScoreEvent;
use App\Services\Scores\ScoreData;
use App\Services\Scores\ScoreIntake;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Games, players and events as the hiscores front reads them (docs/DECISIONS.md D67).

beforeEach(function () {
    $this->member = Member::factory()->create();
    $this->actingAs($this->member, 'member');
});

/** A score sent by a cabinet, as POST /scores stores it: with its event. */
function frontScore(Game $game, Player $player, int $score, string $table = 'default'): void
{
    $client = Client::factory()->create();
    $player->clients()->attach($client, ['linked_at' => now()]);

    app(ScoreIntake::class)->take($client, [new ScoreData(
        (string) Str::uuid(), $player->uuid, $game->romname, $table, $score, null, now(), null, ScoreAttribution::Initials,
    )]);
}

function catverCategory(string $genre, ?string $subgenre = null): Category
{
    $parent = Category::query()->firstOrCreate(['source' => CategorySource::Catver, 'name' => $genre, 'parent_id' => null]);

    return $subgenre === null
        ? $parent
        : Category::query()->create(['source' => CategorySource::Catver, 'name' => $subgenre, 'parent_id' => $parent->id]);
}

it('needs a member', function () {
    auth('member')->logout();

    foreach (['games', 'games/filters', 'games/pacman', 'players', 'events'] as $path) {
        $this->getJson('/api/v1/front/'.$path)->assertUnauthorized();
    }
});

describe('game list', function () {
    it('lists the games by name, with their leader and ranked players', function () {
        $pacman = Game::factory()->create([
            'romname' => 'pacman', 'description' => 'Pac-Man', 'manufacturer' => 'Namco', 'year' => '1980',
            'player_sim' => 2, 'catver_category_id' => catverCategory('Maze', 'Collect')->id,
        ]);
        Game::factory()->create(['romname' => 'galaga', 'description' => 'Galaga']);
        $ace = publicPlayer('ACE');
        leaderboardScore($pacman, $ace, 5000, attributes: ['achieved_at' => '2026-01-02 10:00:00']);
        leaderboardScore($pacman, publicPlayer('BOB'), 9000, attributes: ['achieved_at' => '2026-01-03 10:00:00']);
        leaderboardScore($pacman, Player::factory()->create(['pseudo_3' => 'PRV']), 99999);

        $response = $this->getJson('/api/v1/front/games')->assertOk();

        expect($response->json('games.*.romname'))->toBe(['galaga', 'pacman'])
            ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 24, 'total' => 2])
            ->and($response->json('games.0.leader'))->toBeNull()
            ->and($response->json('games.0.ranked_players'))->toBe(0)
            ->and($response->json('games.1'))->toMatchArray([
                'romname' => 'pacman', 'description' => 'Pac-Man', 'manufacturer' => 'Namco', 'year' => '1980',
                'genre' => 'Maze', 'subgenre' => 'Collect', 'players' => 2,
                'ranked_players' => 2, 'last_score_at' => '2026-01-03T10:00:00+00:00',
            ])
            ->and($response->json('games.1.leader.player.pseudo_3'))->toBe('BOB')
            ->and($response->json('games.1.leader.score'))->toBe(9000);
    });

    it('gives the hash of each game\'s screenshot, and of no other picture', function () {
        $pacman = Game::factory()->create(['romname' => 'pacman', 'description' => 'Pac-Man']);
        Game::factory()->create(['romname' => 'galaga', 'description' => 'Galaga']);
        foreach (['screenshot' => 'a', 'logo' => 'b'] as $type => $letter) {
            GameMedia::query()->create([
                'game_id' => $pacman->id, 'type' => $type, 'path' => "game-media/pacman/{$type}.png",
                'mime' => 'image/png', 'hash' => str_repeat($letter, 64),
            ]);
        }

        $this->getJson('/api/v1/front/games')->assertOk()
            ->assertJsonPath('games.0.screenshot', null)
            ->assertJsonPath('games.1.screenshot', str_repeat('a', 64));
    });

    it('leaves out an uncatalogued game without a visible score', function () {
        Game::factory()->uncatalogued()->create(['romname' => 'ghost']);
        $known = Game::factory()->uncatalogued()->create(['romname' => 'played']);
        leaderboardScore($known, publicPlayer('ACE'), 100);

        $this->getJson('/api/v1/front/games')->assertJsonPath('games.*.romname', ['played']);
    });

    it('filters by text, genre, manufacturer, year and players', function () {
        Game::factory()->create(['romname' => 'pacman', 'description' => 'Pac-Man', 'manufacturer' => 'Namco', 'year' => '1980', 'player_sim' => 2, 'catver_category_id' => catverCategory('Maze', 'Collect')->id]);
        Game::factory()->create(['romname' => 'galaga', 'description' => 'Galaga', 'manufacturer' => 'Namco', 'year' => '1981', 'player_sim' => 1, 'catver_category_id' => catverCategory('Shooter')->id]);
        Game::factory()->create(['romname' => 'dkong', 'description' => 'Donkey Kong', 'manufacturer' => 'Nintendo', 'year' => '1981', 'player_sim' => 1]);

        foreach ([
            'q=pac' => ['pacman'], 'q=DKONG' => ['dkong'], 'genre=Maze' => ['pacman'], 'genre=Shooter' => ['galaga'],
            'manufacturer=Namco' => ['galaga', 'pacman'], 'year=1981' => ['dkong', 'galaga'], 'players=2' => ['pacman'],
            'manufacturer=Namco&year=1981' => ['galaga'], 'q=100%25' => [],
        ] as $query => $romnames) {
            $this->getJson('/api/v1/front/games?'.$query)->assertOk()->assertJsonPath('games.*.romname', $romnames);
        }
    });

    it('filters by scores: any, mine, where I am not ranked', function () {
        $mine = publicPlayer('ACE');
        $this->member->players()->attach($mine, ['linked_at' => now()]);
        $both = Game::factory()->create(['romname' => 'both', 'description' => 'Both']);
        $theirs = Game::factory()->create(['romname' => 'theirs', 'description' => 'Theirs']);
        Game::factory()->create(['romname' => 'empty']);
        leaderboardScore($both, $mine, 100);
        leaderboardScore($both, publicPlayer('BOB'), 200);
        leaderboardScore($theirs, publicPlayer('CAT'), 300);

        $this->getJson('/api/v1/front/games?scores=with')->assertJsonPath('games.*.romname', ['both', 'theirs']);
        $this->getJson('/api/v1/front/games?scores=mine')->assertJsonPath('games.*.romname', ['both']);
        $this->getJson('/api/v1/front/games?scores=unranked')->assertJsonPath('games.*.romname', ['theirs']);
    });

    it('sorts by ranked players, activity and year', function () {
        $a = Game::factory()->create(['romname' => 'aaa', 'description' => 'A', 'year' => '1990']);
        $b = Game::factory()->create(['romname' => 'bbb', 'description' => 'B', 'year' => '1980']);
        Game::factory()->create(['romname' => 'ccc', 'description' => 'C', 'year' => null]);
        leaderboardScore($a, publicPlayer('ACE'), 100, attributes: ['achieved_at' => '2026-03-01 00:00:00']);
        leaderboardScore($b, publicPlayer('BOB'), 100, attributes: ['achieved_at' => '2026-01-01 00:00:00']);
        leaderboardScore($b, publicPlayer('CAT'), 200, attributes: ['achieved_at' => '2026-02-01 00:00:00']);

        $this->getJson('/api/v1/front/games?sort=players')->assertJsonPath('games.*.romname', ['bbb', 'aaa', 'ccc']);
        $this->getJson('/api/v1/front/games?sort=activity')->assertJsonPath('games.*.romname', ['aaa', 'bbb', 'ccc']);
        $this->getJson('/api/v1/front/games?sort=year')->assertJsonPath('games.*.romname', ['bbb', 'aaa', 'ccc']);
    });

    it('pages the list', function () {
        foreach (['a', 'b', 'c'] as $name) {
            Game::factory()->create(['romname' => $name, 'description' => $name]);
        }

        $this->getJson('/api/v1/front/games?per_page=2&page=2')
            ->assertJsonPath('games.*.romname', ['c'])
            ->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 3]);
        $this->getJson('/api/v1/front/games?per_page=101')->assertUnprocessable();
    });

    it('offers the filters catalogued games have', function () {
        Game::factory()->create(['manufacturer' => 'Namco', 'year' => '1980', 'catver_category_id' => catverCategory('Maze', 'Collect')->id]);
        Game::factory()->create(['manufacturer' => 'Atari', 'year' => '1979', 'catver_category_id' => catverCategory('Shooter')->id]);
        Game::factory()->uncatalogued()->create();
        catverCategory('Unused');

        $this->getJson('/api/v1/front/games/filters')->assertOk()->assertExactJson([
            'genres' => ['Maze', 'Shooter'],
            'manufacturers' => ['Atari', 'Namco'],
            'years' => ['1979', '1980'],
        ]);
    });
});

describe('game page', function () {
    it('shows the game, its whole leaderboards, stats and events', function () {
        $parent = Game::factory()->create(['romname' => 'puckman', 'description' => 'Puck Man']);
        $game = Game::factory()->create(['romname' => 'pacman', 'description' => 'Pac-Man', 'parent_romname' => 'puckman', 'player_alt' => 2]);
        Game::factory()->create(['romname' => 'pacmanf', 'description' => 'Pac-Man (fast)', 'parent_romname' => 'pacman']);
        foreach (range(1, 11) as $index) {
            frontScore($game, publicPlayer('P'.chr(64 + $index).'X'), $index * 100);
        }
        frontScore($game, publicPlayer('ALT'), 50, 'hard');
        frontScore($game, Player::factory()->create(['pseudo_3' => 'PRV']), 99999);

        $response = $this->getJson('/api/v1/front/games/pacman')->assertOk();

        expect($response->json('game'))->toMatchArray([
            'romname' => 'pacman', 'description' => 'Pac-Man', 'players_alt' => 2, 'mature' => false, 'catalogued' => true,
            'parent' => ['romname' => $parent->romname, 'description' => 'Puck Man'],
            'clones' => [['romname' => 'pacmanf', 'description' => 'Pac-Man (fast)']],
        ])
            ->and($response->json('leaderboards.*.table'))->toBe(['default', 'hard'])
            // Beyond the top 9 a cabinet shows.
            ->and($response->json('leaderboards.0.entries'))->toHaveCount(11)
            ->and($response->json('leaderboards.0.entries.0'))->toMatchArray(['rank' => 1, 'score' => 1100, 'attribution' => 'initials'])
            ->and($response->json('leaderboards.0.entries.0.player.pseudo_3'))->toBe('PKX')
            ->and($response->json('leaderboards.0.entries.10.rank'))->toBe(11)
            ->and($response->json('leaderboards.1.entries.0.player.pseudo_3'))->toBe('ALT')
            ->and($response->json('stats.ranked_players'))->toBe(12)
            ->and($response->json('stats.scores'))->toBe(12)
            ->and($response->json('events'))->toHaveCount(10)
            ->and($response->json('events.0.player.pseudo_3'))->toBe('ALT');
    });

    it('answers 404 for an unknown game', function () {
        $this->getJson('/api/v1/front/games/nothere')->assertNotFound()->assertJsonPath('code', 'game_not_found');
        $this->getJson('/api/v1/front/games/Not%20A%20Rom')->assertNotFound()->assertJsonPath('code', 'game_not_found');
    });
});

describe('players', function () {
    it('lists the ranked players, most crowns first', function () {
        $one = Game::factory()->create();
        $two = Game::factory()->create();
        leaderboardScore($one, publicPlayer('ACE'), 300);
        leaderboardScore($one, publicPlayer('BOB'), 200);
        leaderboardScore($one, publicPlayer('CAT'), 100);
        leaderboardScore($two, publicPlayer('DAN'), 100);
        leaderboardScore($two, Player::query()->where('pseudo_3', 'ACE')->firstOrFail(), 500);
        leaderboardScore($two, Player::factory()->create(['pseudo_3' => 'PRV']), 9999);
        publicPlayer('NEW');

        $response = $this->getJson('/api/v1/front/players')->assertOk();

        expect($response->json('players.*.pseudo_3'))->toBe(['ACE', 'BOB', 'CAT', 'DAN'])
            ->and($response->json('players.0'))->toMatchArray(['games' => 2, 'crowns' => 2, 'podiums' => 2, 'beaten' => 3])
            ->and($response->json('players.3'))->toMatchArray(['games' => 1, 'crowns' => 0, 'podiums' => 1, 'beaten' => 0]);

        $this->getJson('/api/v1/front/players?q=b')->assertJsonPath('players.*.pseudo_3', ['BOB']);
    });

    it('shows a player with the rank of each of its bests', function () {
        $game = Game::factory()->create(['romname' => 'pacman', 'description' => 'Pac-Man']);
        $ace = publicPlayer('ACE');
        leaderboardScore($game, $ace, 100, attributes: ['achieved_at' => '2026-01-01 00:00:00']);
        leaderboardScore($game, $ace, 200, attributes: ['achieved_at' => '2026-01-02 00:00:00']);
        leaderboardScore($game, publicPlayer('BOB'), 300);

        $this->getJson('/api/v1/front/players/'.$ace->uuid)->assertOk()->assertExactJson([
            'player' => ['id' => $ace->uuid, 'pseudo_3' => 'ACE', 'avatar' => null, 'is_public' => true, 'is_mine' => false],
            'stats' => ['games' => 1, 'crowns' => 0, 'podiums' => 1, 'beaten' => 0, 'last_score_at' => '2026-01-02T00:00:00+00:00', 'points' => 20, 'global_rank' => 2],
            'bests' => [[
                'game' => ['romname' => 'pacman', 'description' => 'Pac-Man'],
                'table' => 'default', 'score' => 200, 'rank' => 2, 'players' => 2,
                'achieved_at' => '2026-01-02T00:00:00+00:00',
                'scores' => 2, 'points' => 20, 'counted' => true,
                'above' => ['player' => ['id' => Player::query()->where('pseudo_3', 'BOB')->value('uuid'), 'pseudo_3' => 'BOB', 'avatar' => null], 'score' => 300],
                'below' => null,
            ]],
            'activity' => [['date' => '2026-01-01', 'bests' => 1], ['date' => '2026-01-02', 'bests' => 1]],
        ]);
    });

    it('tells who is just above and just below on each leaderboard', function () {
        $game = Game::factory()->create();
        leaderboardScore($game, publicPlayer('TOP'), 900);
        $mid = publicPlayer('MID');
        leaderboardScore($game, $mid, 500);
        leaderboardScore($game, publicPlayer('LOW'), 100);
        leaderboardScore($game, Player::factory()->create(['pseudo_3' => 'PRV']), 600);

        $this->getJson('/api/v1/front/players/'.$mid->uuid)
            ->assertJsonPath('bests.0.above.player.pseudo_3', 'TOP')->assertJsonPath('bests.0.above.score', 900)
            ->assertJsonPath('bests.0.below.player.pseudo_3', 'LOW')->assertJsonPath('bests.0.below.score', 100);
    });

    it('gives the history of a player on a game, with the scores to reach', function () {
        $game = Game::factory()->create(['romname' => 'pacman', 'description' => 'Pac-Man']);
        $ace = publicPlayer('ACE');
        leaderboardScore($game, $ace, 200, attributes: ['achieved_at' => '2026-01-02 00:00:00']);
        leaderboardScore($game, $ace, 100, attributes: ['achieved_at' => '2026-01-01 00:00:00']);
        leaderboardScore($game, $ace, 999, attributes: ['achieved_at' => '2026-01-03 00:00:00', 'hidden_at' => now()]);
        leaderboardScore($game, $ace, 50, attributes: ['table' => 'hard']);
        leaderboardScore($game, publicPlayer('BOB'), 300);
        leaderboardScore($game, publicPlayer('TOP'), 900);

        $this->getJson('/api/v1/front/players/'.$ace->uuid.'/games/pacman')->assertOk()
            ->assertJsonPath('game', ['romname' => 'pacman', 'description' => 'Pac-Man'])
            ->assertJsonPath('table', 'default')
            ->assertJsonPath('rank', 3)->assertJsonPath('players', 3)
            ->assertJsonPath('history', [
                ['score' => 100, 'achieved_at' => '2026-01-01T00:00:00+00:00'],
                ['score' => 200, 'achieved_at' => '2026-01-02T00:00:00+00:00'],
            ])
            ->assertJsonPath('leader.player.pseudo_3', 'TOP')->assertJsonPath('leader.score', 900)
            ->assertJsonPath('above.player.pseudo_3', 'BOB')->assertJsonPath('above.score', 300);

        $this->getJson('/api/v1/front/players/'.$ace->uuid.'/games/pacman?table=hard')
            ->assertJsonPath('history.0.score', 50)->assertJsonPath('rank', 1)->assertJsonPath('above', null);
        $this->getJson('/api/v1/front/players/'.$ace->uuid.'/games/nothere')->assertNotFound()->assertJsonPath('code', 'game_not_found');
        $this->getJson('/api/v1/front/players/'.Player::factory()->create()->uuid.'/games/pacman')->assertNotFound()->assertJsonPath('code', 'player_not_found');
    });

    it('gives a member the history of its own private player, without a rank', function () {
        $game = Game::factory()->create(['romname' => 'pacman']);
        $private = Player::factory()->create(['pseudo_3' => 'PRV']);
        $this->member->players()->attach($private, ['linked_at' => now()]);
        leaderboardScore($game, $private, 100);
        leaderboardScore($game, publicPlayer('TOP'), 900);

        $this->getJson('/api/v1/front/players/'.$private->uuid.'/games/pacman')->assertOk()
            ->assertJsonPath('history.0.score', 100)
            ->assertJsonPath('rank', null)->assertJsonPath('above', null)
            ->assertJsonPath('leader.player.pseudo_3', 'TOP');
        $this->getJson('/api/v1/front/players/'.$private->uuid)->assertJsonPath('activity.0.bests', 1);
    });

    it('hides a private or disabled player, but shows the member its own private player', function () {
        $private = Player::factory()->create(['pseudo_3' => 'PRV']);
        $disabled = Player::factory()->disabled()->create(['pseudo_3' => 'BAD', 'is_public' => true]);
        leaderboardScore(Game::factory()->create(), $private, 100);

        $this->getJson('/api/v1/front/players/'.$private->uuid)->assertNotFound()->assertJsonPath('code', 'player_not_found');
        $this->getJson('/api/v1/front/players/'.$disabled->uuid)->assertNotFound();
        $this->getJson('/api/v1/front/players/not-a-uuid')->assertNotFound();

        $this->member->players()->attach($private, ['linked_at' => now()]);
        $this->getJson('/api/v1/front/players/'.$private->uuid)->assertOk()
            ->assertJsonPath('player.is_mine', true)
            ->assertJsonPath('player.is_public', false)
            ->assertJsonPath('stats.games', 0)
            ->assertJsonPath('bests.0.score', 100)
            ->assertJsonPath('bests.0.rank', null);
    });

    it('serves the avatar of a visible player', function () {
        Storage::fake('local');
        $player = publicPlayer('ACE');
        Storage::disk('local')->put('avatars/'.$player->uuid.'.png', 'png-bytes');
        $hash = hash('sha256', 'png-bytes');
        $player->forceFill(['avatar_hash' => $hash])->save();
        $private = Player::factory()->create();

        $this->get('/api/v1/front/players/'.$player->uuid.'/avatar')
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('ETag', '"'.$hash.'"');
        $this->get('/api/v1/front/players/'.$player->uuid.'/avatar', ['If-None-Match' => '"'.$hash.'"'])->assertStatus(304);
        $this->getJson('/api/v1/front/players/'.publicPlayer('BOB')->uuid.'/avatar')->assertNotFound()->assertJsonPath('code', 'avatar_not_found');
        $this->getJson('/api/v1/front/players/'.$private->uuid.'/avatar')->assertNotFound()->assertJsonPath('code', 'player_not_found');
    });
});

describe('events', function () {
    it('lists the events, latest first, old ones included, page by page', function () {
        $game = Game::factory()->create();
        $ace = publicPlayer('ACE');
        frontScore($game, $ace, 100);
        frontScore($game, publicPlayer('BOB'), 200);
        frontScore($game, $ace, 300);
        frontScore($game, Player::factory()->create(['pseudo_3' => 'PRV']), 999);
        ScoreEvent::query()->oldest('id')->first()->forceFill(['announceable' => false])->save();

        $first = $this->getJson('/api/v1/front/events?limit=2')->assertOk();
        expect($first->json('events.*.player.pseudo_3'))->toBe(['ACE', 'BOB'])
            ->and($first->json('events.0'))->toHaveKeys(['id', 'movement', 'flavors', 'message', 'occurred_at', 'game', 'score', 'rank_after'])
            ->and($first->json('cursor'))->toBe($first->json('events.1.id'));

        $second = $this->getJson('/api/v1/front/events?limit=2&before='.$first->json('cursor'))->assertOk();
        expect($second->json('events.*.score'))->toBe([100])
            ->and($second->json('cursor'))->toBeNull();

        $this->getJson('/api/v1/front/events?player='.$ace->uuid)->assertJsonPath('events.*.score', [300, 100]);
    });
});
