<?php

use App\Enums\PlayerStatus;
use App\Models\Client;
use App\Models\Game;
use App\Models\Member;
use App\Models\Player;
use App\Models\PlayerOpinion;

// The votes of the members' players on the hiscores front (docs/DECISIONS.md D86).

beforeEach(function () {
    config(['front.url' => 'https://hiscores.test']);
    $this->member = Member::factory()->create();
    $this->player = Player::factory()->create(['pseudo_3' => 'ACE']);
    $this->member->players()->attach($this->player, ['linked_at' => now()]);
    $this->actingAs($this->member, 'member');
});

/** What a browser sends with a request that changes something. */
const VOTE_ORIGIN = ['Origin' => 'https://hiscores.test'];

function votable(string $romname, string $description, bool $hiscores = true): Game
{
    return Game::factory()->create(['romname' => $romname, 'description' => $description, 'hiscores' => $hiscores]);
}

describe('voting', function () {
    it('records the vote of the member\'s player, and changes it', function () {
        $game = votable('pacman', 'Pac-Man');

        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 1], VOTE_ORIGIN)
            ->assertOk()
            ->assertExactJson(['romname' => 'pacman', 'vote' => 1]);
        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => -1], VOTE_ORIGIN)->assertOk();

        $opinion = PlayerOpinion::query()->sole();
        expect($opinion->player_id)->toBe($this->player->id)
            ->and($opinion->game_id)->toBe($game->id)
            ->and($opinion->vote)->toBe(-1)
            ->and($opinion->voted_at)->not->toBeNull();
    });

    it('forgets the vote when it is set back to neutral', function () {
        votable('pacman', 'Pac-Man');
        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 1], VOTE_ORIGIN);

        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 0], VOTE_ORIGIN)
            ->assertOk()
            ->assertJsonPath('vote', 0);

        expect(PlayerOpinion::query()->count())->toBe(0);
    });

    it('refuses a vote that is not a thumb', function () {
        votable('pacman', 'Pac-Man');

        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 2], VOTE_ORIGIN)->assertUnprocessable();
        $this->putJson('/api/v1/front/me/votes/pacman', [], VOTE_ORIGIN)->assertUnprocessable();
    });

    it('refuses a game whose hiscores no cabinet can read', function () {
        votable('pong', 'Pong', hiscores: false);

        $this->putJson('/api/v1/front/me/votes/pong', ['vote' => 1], VOTE_ORIGIN)
            ->assertNotFound()
            ->assertJsonPath('code', 'game_not_found');
        $this->putJson('/api/v1/front/me/votes/nothere', ['vote' => 1], VOTE_ORIGIN)->assertNotFound();
    });

    it('needs a linked player', function () {
        votable('pacman', 'Pac-Man');
        $this->actingAs(Member::factory()->create(), 'member');

        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 1], VOTE_ORIGIN)
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
        $this->getJson('/api/v1/front/me/votes')
            ->assertNotFound()
            ->assertJsonPath('code', 'player_not_found');
    });

    it('refuses a disabled player', function () {
        votable('pacman', 'Pac-Man');
        $this->player->forceFill(['status' => PlayerStatus::Disabled])->save();

        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 1], VOTE_ORIGIN)
            ->assertForbidden()
            ->assertJsonPath('code', 'player_disabled');
    });

    it('keeps the vote with the player, not the member', function () {
        votable('pacman', 'Pac-Man');
        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => 1], VOTE_ORIGIN);

        $this->member->players()->detach();
        $other = Member::factory()->create();
        $other->players()->attach($this->player, ['linked_at' => now()]);

        $this->actingAs($other, 'member')->getJson('/api/v1/front/me/votes?vote=up')
            ->assertJsonPath('games.*.romname', ['pacman']);
    });
});

describe('the list of votes', function () {
    beforeEach(function () {
        votable('dkong', 'Donkey Kong');
        votable('galaga', 'Galaga');
        votable('pacman', 'Pac-Man');
        votable('pong', 'Pong', hiscores: false);
        $this->putJson('/api/v1/front/me/votes/galaga', ['vote' => 1], VOTE_ORIGIN);
        $this->putJson('/api/v1/front/me/votes/pacman', ['vote' => -1], VOTE_ORIGIN);
    });

    it('lists every game with readable hiscores, by name, with the player\'s vote', function () {
        $this->getJson('/api/v1/front/me/votes')->assertOk()
            ->assertJsonPath('games.*.romname', ['dkong', 'galaga', 'pacman'])
            ->assertJsonPath('games.*.my_vote', [0, 1, -1])
            ->assertJsonPath('games.0.description', 'Donkey Kong')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('counts', ['all' => 3, 'up' => 1, 'down' => 1, 'none' => 1]);
    });

    it('filters by vote and by name', function () {
        $this->getJson('/api/v1/front/me/votes?vote=up')->assertJsonPath('games.*.romname', ['galaga']);
        $this->getJson('/api/v1/front/me/votes?vote=down')->assertJsonPath('games.*.romname', ['pacman']);
        $this->getJson('/api/v1/front/me/votes?vote=none')->assertJsonPath('games.*.romname', ['dkong']);
        $this->getJson('/api/v1/front/me/votes?q=kong')->assertJsonPath('games.*.romname', ['dkong']);
        $this->getJson('/api/v1/front/me/votes?q=gal&vote=none')->assertJsonPath('games', []);
    });

    it('pages the list', function () {
        $this->getJson('/api/v1/front/me/votes?per_page=2&page=2')
            ->assertJsonPath('games.*.romname', ['pacman'])
            ->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 3]);
    });

    it('shows only the member\'s own votes', function () {
        $other = Member::factory()->create();
        $other->players()->attach(Player::factory()->create(), ['linked_at' => now()]);

        $this->actingAs($other, 'member')->getJson('/api/v1/front/me/votes')
            ->assertJsonPath('games.*.my_vote', [0, 0, 0]);
    });
});

describe('a game\'s page', function () {
    it('gives the vote of the member\'s player', function () {
        votable('galaga', 'Galaga');
        $this->putJson('/api/v1/front/me/votes/galaga', ['vote' => 1], VOTE_ORIGIN);

        $this->getJson('/api/v1/front/games/galaga')->assertJsonPath('my_vote', 1);
    });

    it('gives no vote without a player, nor on a game nobody can score on', function () {
        votable('pong', 'Pong', hiscores: false);
        votable('galaga', 'Galaga');

        $this->getJson('/api/v1/front/games/pong')->assertJsonPath('my_vote', null);
        $this->actingAs(Member::factory()->create(), 'member')
            ->getJson('/api/v1/front/games/galaga')->assertJsonPath('my_vote', null);
    });

    it('counts the players\' votes with the cabinets\' ones', function () {
        config(['hiscores.popularity.min_votes' => 2]);
        $game = votable('galaga', 'Galaga');
        $game->opinions()->create(['client_id' => Client::factory()->create()->id, 'vote' => -1, 'play_count' => 1]);
        $this->putJson('/api/v1/front/me/votes/galaga', ['vote' => -1], VOTE_ORIGIN);

        $this->getJson('/api/v1/front/games/galaga')
            ->assertJsonPath('popularity.label', 'missed_date')
            ->assertJsonPath('popularity.votes', 2)
            ->assertJsonPath('popularity.thumbs_up', 0);
    });
});
