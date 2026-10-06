<?php

use App\Enums\CategorySource;
use App\Models\Category;
use App\Models\Client;
use App\Models\Game;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

// Catalog fed by service accounts (docs/DECISIONS.md D47).

/**
 * A complete catalog entry, as maui-repository's push-catalog sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogGame(array $overrides = []): array
{
    return [
        'romname' => 'dkong',
        'description' => 'Donkey Kong (US set 1)',
        'manufacturer' => 'Nintendo of America',
        'year' => '1981',
        'parent_romname' => null,
        'player_sim' => 1,
        'player_alt' => 2,
        'genre' => 'Platform',
        'catver_genre' => 'Platform',
        'catver_subgenre' => 'Run Jump',
        'mature' => false,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $games
 * @param  array<string, string>|null  $headers
 */
function putCatalog(array $games, ?array $headers = null): TestResponse
{
    if ($headers === null) {
        [$client, $token] = serviceWithToken();
        $headers = serviceHeaders($client, $token);
    }

    return test()->putJson('/api/v1/catalog/games', ['games' => $games], $headers);
}

it('creates games and their categories', function () {
    putCatalog([
        catalogGame(),
        catalogGame(['romname' => 'dkongjp', 'description' => 'Donkey Kong (Japan set 1)', 'parent_romname' => 'dkong']),
    ])
        ->assertOk()
        ->assertExactJson(['received' => 2, 'created' => 2, 'updated' => 0, 'unchanged' => 0]);

    $game = Game::query()->where('romname', 'dkong')->firstOrFail();
    expect($game->description)->toBe('Donkey Kong (US set 1)')
        ->and($game->manufacturer)->toBe('Nintendo of America')
        ->and($game->year)->toBe('1981')
        ->and($game->player_sim)->toBe(1)
        ->and($game->player_alt)->toBe(2)
        ->and($game->mature)->toBeFalse()
        ->and($game->catalogued_at)->not->toBeNull()
        ->and($game->genreCategory?->name)->toBe('Platform')
        ->and($game->genreCategory?->source)->toBe(CategorySource::Genre)
        ->and($game->catverCategory?->name)->toBe('Run Jump')
        ->and($game->catverCategory?->parent?->name)->toBe('Platform')
        ->and($game->catverCategory?->parent?->source)->toBe(CategorySource::Catver);

    expect(Game::query()->where('romname', 'dkongjp')->value('parent_romname'))->toBe('dkong')
        ->and(Category::query()->count())->toBe(3);
});

it('is idempotent', function () {
    [$client, $token] = serviceWithToken();
    $headers = serviceHeaders($client, $token);
    $games = [catalogGame(), catalogGame(['romname' => 'pacman', 'description' => 'Pac-Man (Midway)', 'catver_subgenre' => null, 'catver_genre' => 'Maze', 'genre' => 'Maze'])];

    putCatalog($games, $headers)->assertOk();

    putCatalog($games, $headers)
        ->assertOk()
        ->assertExactJson(['received' => 2, 'created' => 0, 'updated' => 0, 'unchanged' => 2]);

    // genre Platform and Maze, catver Platform > Run Jump and Maze.
    expect(Game::query()->count())->toBe(2)
        ->and(Category::query()->count())->toBe(5);
});

it('files a catver genre without subgenre under the genre itself', function () {
    putCatalog([catalogGame(['catver_genre' => 'Maze', 'catver_subgenre' => null])])->assertOk();

    $category = Game::query()->firstOrFail()->catverCategory;
    expect($category?->name)->toBe('Maze')
        ->and($category?->parent_id)->toBeNull()
        ->and($category?->source)->toBe(CategorySource::Catver);
});

it('updates changed games', function () {
    [$client, $token] = serviceWithToken();
    $headers = serviceHeaders($client, $token);
    putCatalog([catalogGame()], $headers)->assertOk();

    putCatalog([catalogGame(['description' => 'Donkey Kong (US set 1, fixed)', 'catver_subgenre' => 'Climb'])], $headers)
        ->assertOk()
        ->assertExactJson(['received' => 1, 'created' => 0, 'updated' => 1, 'unchanged' => 0]);

    $game = Game::query()->firstOrFail();
    expect($game->description)->toBe('Donkey Kong (US set 1, fixed)')
        ->and($game->catverCategory?->name)->toBe('Climb');
});

it('completes a game known only from a score', function () {
    Game::factory()->uncatalogued()->create(['romname' => 'dkong']);

    putCatalog([catalogGame()])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'created' => 0, 'updated' => 1, 'unchanged' => 0]);

    $game = Game::query()->firstOrFail();
    expect($game->catalogued_at)->not->toBeNull()
        ->and($game->description)->toBe('Donkey Kong (US set 1)');
});

it('never deletes the games missing from a push', function () {
    Game::factory()->create(['romname' => 'galaga']);

    putCatalog([catalogGame()])->assertOk();

    expect(Game::query()->pluck('romname')->sort()->values()->all())->toBe(['dkong', 'galaga']);
});

it('accepts a game with its romname and description only', function () {
    putCatalog([['romname' => 'mygame', 'description' => 'My Game']])->assertOk();

    $game = Game::query()->firstOrFail();
    expect($game->manufacturer)->toBeNull()
        ->and($game->genre_category_id)->toBeNull()
        ->and($game->catver_category_id)->toBeNull()
        ->and($game->mature)->toBeFalse();
});

it('records when the service account was last used (D43)', function () {
    [$client, $token] = serviceWithToken();

    putCatalog([catalogGame()], serviceHeaders($client, $token))->assertOk();

    expect(PersonalAccessToken::findToken($token)?->last_used_at)->not->toBeNull();
});

it('rejects cabinets', function () {
    [$client, $token] = cabinetWithToken();

    putCatalog([catalogGame()], cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');

    expect(Game::query()->count())->toBe(0);
});

it('rejects a cabinet even with the catalog ability', function () {
    $client = Client::factory()->create();
    $token = $client->createToken('forged', ['catalog:write'])->plainTextToken;

    putCatalog([catalogGame()], cabinetHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

it('rejects a disabled service account', function () {
    [$client, $token] = serviceWithToken();
    $client->disable();

    putCatalog([catalogGame()], serviceHeaders($client, $token))
        ->assertForbidden()
        ->assertJsonPath('code', 'client_disabled');
});

it('rejects missing credentials', function () {
    putCatalog([catalogGame()], [])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects invalid batches without writing anything', function (array $games, string $field) {
    putCatalog($games)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$field]]);

    expect(Game::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0);
})->with([
    'no games' => [[], 'games'],
    'uppercase romname' => [[catalogGame(['romname' => 'DKong'])], 'games.0.romname'],
    'romname too long' => [[catalogGame(['romname' => str_repeat('a', 33)])], 'games.0.romname'],
    'missing description' => [[catalogGame(['description' => null])], 'games.0.description'],
    'invalid parent' => [[catalogGame(['parent_romname' => 'Not a romname'])], 'games.0.parent_romname'],
    'negative players' => [[catalogGame(['player_sim' => -1])], 'games.0.player_sim'],
    'subgenre without genre' => [[catalogGame(['catver_genre' => null])], 'games.0.catver_genre'],
    'romname twice' => [[catalogGame(), catalogGame()], 'games.1.romname'],
    'valid game next to an invalid one' => [[catalogGame(['romname' => 'pacman']), catalogGame(['year' => '19811'])], 'games.1.year'],
]);

it('caps the batch size', function () {
    $games = array_map(fn (int $i): array => ['romname' => "game{$i}", 'description' => "Game {$i}"], range(1, 501));

    putCatalog($games)
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['games']]);
});
