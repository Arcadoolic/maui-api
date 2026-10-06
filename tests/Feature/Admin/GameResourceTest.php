<?php

use App\Enums\CategorySource;
use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Games\Pages\ViewGame;
use App\Models\Category;
use App\Models\Game;
use App\Models\Player;
use App\Models\Score;
use App\Models\User;

use function Pest\Livewire\livewire;

// Catalog in the back office, read only (docs/DECISIONS.md D47).

beforeEach(function () {
    $this->actingAs(User::factory()->withAppAuthentication()->create());
});

it('lists games', function () {
    $games = Game::factory()->count(3)->create();

    livewire(ListGames::class)->assertCanSeeTableRecords($games);
});

it('filters games by catver category, subgenres included', function () {
    $platform = Category::query()->create(['source' => CategorySource::Catver, 'name' => 'Platform']);
    $runJump = Category::query()->create(['source' => CategorySource::Catver, 'name' => 'Run Jump', 'parent_id' => $platform->id]);
    $maze = Category::query()->create(['source' => CategorySource::Catver, 'name' => 'Maze']);
    $dkong = Game::factory()->create(['catver_category_id' => $runJump->id]);
    $pacman = Game::factory()->create(['catver_category_id' => $maze->id]);

    livewire(ListGames::class)
        ->filterTable('catver_category', $platform->id)
        ->assertCanSeeTableRecords([$dkong])
        ->assertCanNotSeeTableRecords([$pacman]);
});

it('filters games by simultaneous players', function () {
    $solo = Game::factory()->create(['player_sim' => 1]);
    $versus = Game::factory()->create(['player_sim' => 2]);

    livewire(ListGames::class)
        ->filterTable('player_sim', 2)
        ->assertCanSeeTableRecords([$versus])
        ->assertCanNotSeeTableRecords([$solo]);
});

it('filters games known from a score only', function () {
    $catalogued = Game::factory()->create();
    $uncatalogued = Game::factory()->uncatalogued()->create();

    livewire(ListGames::class)
        ->filterTable('catalogued', false)
        ->assertCanSeeTableRecords([$uncatalogued])
        ->assertCanNotSeeTableRecords([$catalogued]);
});

it('shows a game', function () {
    $game = Game::factory()->create(['romname' => 'dkong', 'description' => 'Donkey Kong (US set 1)']);

    livewire(ViewGame::class, ['record' => $game->getRouteKey()])
        ->assertOk()
        ->assertSee('Donkey Kong (US set 1)');
});

it('is read only', function () {
    expect(GameResource::canCreate())->toBeFalse()
        ->and(GameResource::canEdit(Game::factory()->create()))->toBeFalse()
        ->and(GameResource::canDelete(Game::factory()->create()))->toBeFalse()
        ->and(array_keys(GameResource::getPages()))->toBe(['index', 'view']);
});

it('shows the leaderboard of a game, as the cabinets get it (D52)', function () {
    $game = Game::factory()->create();
    $public = Player::factory()->create(['pseudo_3' => 'NOB', 'is_public' => true]);
    $private = Player::factory()->create(['pseudo_3' => 'PRV', 'is_public' => false]);
    Score::factory()->create(['game_id' => $game->id, 'player_id' => $public->id, 'score' => 19_200]);
    Score::factory()->create(['game_id' => $game->id, 'player_id' => $private->id, 'score' => 99_999]);

    livewire(ViewGame::class, ['record' => $game->getRouteKey()])
        ->assertSee('NOB')
        ->assertSee('19,200')
        ->assertDontSee('PRV');
});

it('filters the games with scores', function () {
    $played = Game::factory()->create();
    Score::factory()->create(['game_id' => $played->id]);
    $never = Game::factory()->create();

    livewire(ListGames::class)
        ->filterTable('scores', true)
        ->assertCanSeeTableRecords([$played])
        ->assertCanNotSeeTableRecords([$never]);
});
