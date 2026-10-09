<?php

namespace App\Http\Controllers\Front;

use App\Http\Middleware\AuthenticateMember;
use App\Http\Problems\ApiProblemException;
use App\Models\Category;
use App\Models\Game;
use App\Models\GameDetail;
use App\Models\GameMedia;
use App\Models\Player;
use App\Models\ScoreEvent;
use App\Services\Leaderboards\Leaderboards;
use App\Services\Leaderboards\Rankings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Games of the hiscores front: the list with its filters, and a game's page (docs/DECISIONS.md D67). */
final class GamesController
{
    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 100;

    /** Events shown on a game's page. */
    public const PAGE_EVENTS = 10;

    private const ROMNAME = '/^[a-z0-9_]{1,32}$/';

    public function __construct(private readonly Rankings $rankings) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'genre' => ['sometimes', 'string', 'max:128'],
            'manufacturer' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'string', 'max:4'],
            'players' => ['sometimes', 'integer', 'min:1', 'max:8'],
            // with: any visible score; mine: one of my players is ranked; unranked: scores, but none of mine.
            'scores' => ['sometimes', Rule::in(['with', 'mine', 'unranked'])],
            'sort' => ['sometimes', Rule::in(['name', 'year', 'players', 'activity'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);

        $query = Game::query()
            ->leftJoinSub($this->rankings->gameTotals(), 'totals', 'totals.game_id', '=', 'games.id')
            ->select('games.*', 'totals.ranked_players', 'totals.last_score_at')
            // A game nobody catalogued is only worth listing for its scores.
            ->where(fn (Builder $game) => $game->whereNotNull('games.catalogued_at')->orWhereNotNull('totals.game_id'));
        $this->filter($query, $filters, array_map(intval(...), AuthenticateMember::member($request)->players()->pluck('players.id')->all()));

        $total = (clone $query)->count('games.id');
        match ($filters['sort'] ?? 'name') {
            'year' => $query->orderByRaw('games.year is null')->orderBy('games.year'),
            'players' => $query->orderByRaw('coalesce(totals.ranked_players, 0) desc'),
            'activity' => $query->orderByRaw('totals.last_score_at desc nulls last'),
            default => $query,
        };
        $games = $query->orderBy('games.description')->orderBy('games.romname')
            // Only the screenshot: the list shows it behind each game.
            ->with(['catverCategory.parent', 'media' => fn ($media) => $media->where('type', 'screenshot')])
            ->forPage($page, $perPage)
            ->get();
        $leaders = $this->leaders($games->modelKeys());

        return new JsonResponse([
            'games' => $games->map(fn (Game $game): array => [
                ...self::summary($game),
                'ranked_players' => (int) ($game->getAttribute('ranked_players') ?? 0),
                'last_score_at' => self::iso($game->getAttribute('last_score_at')),
                'leader' => $leaders->get($game->id),
                // Its hash: GET /front/games/{romname}/media/screenshot.
                'screenshot' => $game->media->first()?->hash,
            ])->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /** What the list can be filtered by: only values that catalogued games have. */
    public function filters(): JsonResponse
    {
        $catalogued = fn () => Game::query()->whereNotNull('catalogued_at');
        $genreIds = $catalogued()->whereNotNull('catver_category_id')->distinct()->pluck('catver_category_id');
        $genres = Category::query()->whereIn('id', $genreIds)->with('parent')->get()
            ->map(fn (Category $category): string => $category->parent->name ?? $category->name)
            ->unique()->sort()->values();

        return new JsonResponse([
            'genres' => $genres->all(),
            'manufacturers' => $catalogued()->whereNotNull('manufacturer')->distinct()->orderBy('manufacturer')->pluck('manufacturer')->all(),
            'years' => $catalogued()->whereNotNull('year')->distinct()->orderBy('year')->pluck('year')->all(),
        ]);
    }

    public function show(string $romname): JsonResponse
    {
        $game = preg_match(self::ROMNAME, $romname) === 1
            ? Game::query()->where('romname', $romname)->with(['catverCategory.parent', 'detail', 'media'])->first()
            : null;
        if ($game === null) {
            throw ApiProblemException::gameNotFound();
        }

        $rows = $this->rankings->rows()
            ->where('ranked.game_id', $game->id)
            ->join('clients', 'clients.id', '=', 'ranked.client_id')
            ->orderBy('ranked.table')
            ->orderBy('ranked.rank')
            ->get(['ranked.*', 'clients.name as cabinet']);
        $players = Player::query()->whereIn('id', $rows->pluck('player_id')->unique())->get()->keyBy('id');

        $leaderboards = $rows->groupBy('table')->map(fn (Collection $entries, string $table): array => [
            'table' => $table,
            'entries' => $entries->map(fn (object $row): array => [
                'rank' => (int) $row->rank,
                'player' => self::player($players->get($row->player_id)),
                'score' => (int) $row->score,
                'achieved_at' => self::iso($row->achieved_at),
                'cabinet' => (string) $row->cabinet,
                'attribution' => (string) $row->attribution,
            ])->values()->all(),
        ])->values();

        $scores = Leaderboards::visibleScores()->where('scores.game_id', $game->id);
        $parent = $game->parent_romname === null ? null : Game::query()->where('romname', $game->parent_romname)->first();

        return new JsonResponse([
            'game' => [
                ...self::summary($game),
                'players_alt' => $game->player_alt,
                'mature' => $game->mature,
                'catalogued' => $game->isCatalogued(),
                'parent' => $parent === null ? null : ['romname' => $parent->romname, 'description' => $parent->description],
                'clones' => Game::query()->where('parent_romname', $game->romname)->orderBy('description')->get()
                    ->map(fn (Game $clone): array => ['romname' => $clone->romname, 'description' => $clone->description])->all(),
            ],
            'details' => self::details($game->detail),
            // By type, the hash of each picture: GET /front/games/{romname}/media/{type}.
            'media' => (object) $game->media->pluck('hash', 'type')->all(),
            'leaderboards' => $leaderboards->all(),
            'stats' => [
                'ranked_players' => $rows->pluck('player_id')->unique()->count(),
                // Personal bests stored for the game: each one beat its player's previous score (D50).
                'scores' => (clone $scores)->count(),
                'first_score_at' => self::iso((clone $scores)->min('scores.achieved_at')),
                'last_score_at' => self::iso((clone $scores)->max('scores.achieved_at')),
            ],
            'events' => EventsController::visibleEvents()
                ->where('game_id', $game->id)
                ->orderByDesc('id')
                ->limit(self::PAGE_EVENTS)
                ->get()
                ->map(fn (ScoreEvent $event): array => $event->toApiArray())
                ->all(),
        ]);
    }

    /** A picture of the game, with its hash as ETag (D68). */
    public function media(Request $request, string $romname, string $type): Response
    {
        $media = preg_match(self::ROMNAME, $romname) === 1 && isset(GameMedia::TYPES[$type])
            ? GameMedia::query()->where('type', $type)->whereHas('game', fn (Builder $game) => $game->where('romname', $romname))->first()
            : null;
        if ($media === null || ! Storage::disk(GameMedia::DISK)->exists($media->path)) {
            throw ApiProblemException::mediaNotFound();
        }

        $response = Storage::disk(GameMedia::DISK)->response($media->path, null, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, no-cache',
        ]);
        $response->setEtag($media->hash);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * What ScreenScraper knows of the game: null until it was asked, or when it does not know it.
     *
     * @return array<string, mixed>|null
     */
    private static function details(?GameDetail $detail): ?array
    {
        return $detail === null || ! $detail->found ? null : [
            'synopsis' => ['fr' => $detail->synopsis_fr, 'en' => $detail->synopsis_en],
            'developer' => $detail->developer,
            'publisher' => $detail->publisher,
            'rating' => $detail->rating,
            'players' => $detail->players,
            'rotation' => $detail->rotation,
            'resolution' => $detail->resolution,
            'controls' => $detail->buttons === null ? null : ['joystick' => (bool) $detail->joystick, 'buttons' => $detail->buttons],
            'genres' => $detail->genres ?? [],
        ];
    }

    /**
     * @param  Builder<Game>  $query
     * @param  array<string, mixed>  $filters
     * @param  array<int>  $myPlayerIds
     */
    private function filter(Builder $query, array $filters, array $myPlayerIds): void
    {
        if (filled($filters['q'] ?? null)) {
            $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn (Builder $game) => $game
                ->where('games.description', 'ilike', $like)
                ->orWhere('games.romname', 'ilike', $like));
        }
        if (filled($filters['genre'] ?? null)) {
            // A catver genre, with its subgenres.
            $query->whereHas('catverCategory', fn (Builder $category) => $category
                ->where('name', $filters['genre'])->whereNull('parent_id')
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('name', $filters['genre'])));
        }
        if (filled($filters['manufacturer'] ?? null)) {
            $query->where('games.manufacturer', $filters['manufacturer']);
        }
        if (filled($filters['year'] ?? null)) {
            $query->where('games.year', $filters['year']);
        }
        if (isset($filters['players'])) {
            $query->where('games.player_sim', '>=', (int) $filters['players']);
        }
        if (isset($filters['scores'])) {
            $query->whereNotNull('totals.game_id');
            $mine = $this->rankings->rows()->whereIn('ranked.player_id', $myPlayerIds)->select('ranked.game_id');
            match ($filters['scores']) {
                'mine' => $query->whereIn('games.id', $mine),
                'unranked' => $query->whereNotIn('games.id', $mine),
                default => null,
            };
        }
    }

    /**
     * The leader of the default table of each game.
     *
     * @param  array<int, int|string>  $gameIds
     * @return Collection<int, array{player: array{id: string, pseudo_3: string, avatar: string|null}, score: int}>
     */
    private function leaders(array $gameIds): Collection
    {
        $rows = $this->rankings->rows()
            ->whereIn('ranked.game_id', $gameIds)
            ->where('ranked.table', 'default')
            ->where('ranked.rank', 1)
            ->get();
        $players = Player::query()->whereIn('id', $rows->pluck('player_id'))->get()->keyBy('id');

        return $rows->mapWithKeys(fn (object $row): array => [(int) $row->game_id => [
            'player' => self::player($players->get($row->player_id)),
            'score' => (int) $row->score,
        ]]);
    }

    /** @return array<string, mixed> */
    private static function summary(Game $game): array
    {
        $category = $game->catverCategory;

        return [
            'romname' => $game->romname,
            'description' => $game->description,
            'manufacturer' => $game->manufacturer,
            'year' => $game->year,
            'genre' => $category === null ? null : ($category->parent->name ?? $category->name),
            'subgenre' => $category?->parent === null ? null : $category->name,
            'players' => $game->player_sim,
        ];
    }

    /** @return array{id: string, pseudo_3: string, avatar: string|null} */
    public static function player(?Player $player): array
    {
        return [
            'id' => (string) $player?->uuid,
            'pseudo_3' => (string) $player?->pseudo_3,
            'avatar' => $player?->avatar_hash,
        ];
    }

    public static function iso(mixed $date): ?string
    {
        return $date === null ? null : Carbon::parse((string) $date, 'UTC')->toIso8601String();
    }
}
