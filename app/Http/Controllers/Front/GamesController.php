<?php

namespace App\Http\Controllers\Front;

use App\Enums\PopularityLabel;
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
use App\Services\Popularity\GamePopularity;
use App\Services\Popularity\Popularity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** Games of each list of the highlights, unless asked otherwise. */
    public const DEFAULT_HIGHLIGHTS = 6;

    public const MAX_HIGHLIGHTS = 24;

    private const ROMNAME = '/^[a-z0-9_]{1,32}$/';

    /** The labels of a game its cabinets liked (D76). */
    private const LIKED = [PopularityLabel::Hit, PopularityLabel::HiddenGem];

    public function __construct(private readonly Rankings $rankings, private readonly Popularity $popularity) {}

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
            'label' => ['sometimes', Rule::enum(PopularityLabel::class)],
            'sort' => ['sometimes', Rule::in(['name', 'year', 'players', 'activity', 'popularity'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);

        $popularity = $this->popularity->all();
        $query = $this->listed();
        $this->filter($query, $filters, self::myPlayerIds($request));
        if (isset($filters['label'])) {
            $query->whereIn('games.id', $popularity
                ->filter(fn (GamePopularity $game): bool => $game->label?->value === $filters['label'])->keys()->all());
        }

        $total = (clone $query)->count('games.id');
        match ($filters['sort'] ?? 'name') {
            'year' => $query->orderByRaw('games.year is null')->orderBy('games.year'),
            'players' => $query->orderByRaw('coalesce(totals.ranked_players, 0) desc'),
            'activity' => $query->orderByRaw('totals.last_score_at desc nulls last'),
            // The most popular first (D76), then the games nobody reported nor scored on.
            'popularity' => self::orderByIds($query, $popularity->keys()->all()),
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
                'popularity' => self::popularitySummary($popularity->get($game->id)),
            ])->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /**
     * Games to put forward (D77): `discover`, the liked games the member's player has no score
     * on, the most popular first; `trending`, the games most played and scored on lately.
     */
    public function highlights(Request $request): JsonResponse
    {
        $limit = (int) ($request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_HIGHLIGHTS],
        ])['limit'] ?? self::DEFAULT_HIGHLIGHTS);
        $popularity = $this->popularity->all();

        $liked = $popularity->filter(fn (GamePopularity $game): bool => in_array($game->label, self::LIKED, true))->keys()->all();
        $mine = $this->rankings->rows()->whereIn('ranked.player_id', self::myPlayerIds($request))->select('ranked.game_id');
        $discover = self::orderByIds($this->listed()->whereIn('games.id', $liked)->whereNotIn('games.id', $mine), $liked);

        $since = now()->subDays(max(1, (int) config('hiscores.popularity.trending_days')));
        $recent = DB::table('game_opinions')->where('last_played_at', '>=', $since)
            ->groupBy('game_id')->selectRaw('game_id, count(*) as recent')->pluck('recent', 'game_id');
        $scored = Leaderboards::visibleScores()->where('scores.achieved_at', '>=', $since)
            ->groupBy('scores.game_id')->selectRaw('scores.game_id, count(*) as recent')->pluck('recent', 'scores.game_id');
        $moves = $recent->keys()->merge($scored->keys())->unique()
            ->mapWithKeys(fn (int|string $id): array => [(int) $id => (int) ($recent[$id] ?? 0) + (int) ($scored[$id] ?? 0)])
            // At equal moves, the more popular game first.
            ->sortBy(fn (int $count, int $id): array => [-$count, $popularity->keys()->search($id)]);
        $trending = self::orderByIds($this->listed()->whereIn('games.id', $moves->keys()->all()), $moves->keys()->all());

        return new JsonResponse([
            'discover' => self::cards($discover, $limit, $popularity),
            'trending' => self::cards($trending, $limit, $popularity),
        ]);
    }

    /**
     * The games every cabinet that voted turned down, and the ones a single cabinet saved from
     * it (D78). Among every game, the ones the list leaves out included (D74): a game turned
     * down is removed from the cabinets, and seldom has hiscores to read.
     */
    public function missedDates(): JsonResponse
    {
        $popularity = $this->popularity->all();
        $minVotes = Popularity::rules()['min_votes'];
        $missed = $popularity->filter(fn (GamePopularity $game): bool => $game->label === PopularityLabel::MissedDate);
        $saved = $popularity->filter(fn (GamePopularity $game): bool => $game->thumbsUp === 1 && $game->thumbsDown >= $minVotes);

        $games = Game::query()->whereIn('id', $missed->keys()->merge($saved->keys())->all())
            ->with(['catverCategory.parent', 'media' => fn ($media) => $media->where('type', 'screenshot')])
            ->get()
            ->keyBy('id');
        $cards = function (Collection $group) use ($games): array {
            $cards = [];
            foreach ($group as $popularity) {
                $game = $games->get($popularity->gameId);
                if ($game !== null) {
                    $cards[] = [
                        ...self::summary($game),
                        'screenshot' => $game->media->first()?->hash,
                        'listed' => $game->hiscores || $popularity->rankedPlayers > 0,
                        'votes' => $popularity->votes(),
                    ];
                }
            }
            // The most cabinets first, then by name.
            usort($cards, fn (array $a, array $b): int => [$b['votes'], $a['description'], $a['romname']] <=> [$a['votes'], $b['description'], $b['romname']]);

            return $cards;
        };

        return new JsonResponse(['missed' => $cards($missed), 'saved' => $cards($saved), 'min_votes' => $minVotes]);
    }

    /** What the list can be filtered by: only values that games with readable hiscores have. */
    public function filters(): JsonResponse
    {
        $listed = fn () => Game::query()->where('hiscores', true);
        $genreIds = $listed()->whereNotNull('catver_category_id')->distinct()->pluck('catver_category_id');
        $genres = Category::query()->whereIn('id', $genreIds)->with('parent')->get()
            ->map(fn (Category $category): string => $category->parent->name ?? $category->name)
            ->unique()->sort()->values();

        $labels = $this->popularity->all()->only($listed()->pluck('id')->all())
            ->map(fn (GamePopularity $game): ?string => $game->label?->value)->filter()->unique();

        return new JsonResponse([
            'genres' => $genres->all(),
            // In the order of the enum, the ones a listed game has.
            'labels' => array_values(array_intersect(array_column(PopularityLabel::cases(), 'value'), $labels->all())),
            'manufacturers' => $listed()->whereNotNull('manufacturer')->distinct()->orderBy('manufacturer')->pluck('manufacturer')->all(),
            'years' => $listed()->whereNotNull('year')->distinct()->orderBy('year')->pluck('year')->all(),
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
            'popularity' => self::popularityDetails($this->popularity->all()->get($game->id)),
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
     * The games the front lists, with their totals (columns ranked_players, last_score_at).
     *
     * @return Builder<Game>
     */
    private function listed(): Builder
    {
        return Game::query()
            ->leftJoinSub($this->rankings->gameTotals(), 'totals', 'totals.game_id', '=', 'games.id')
            ->select('games.*', 'totals.ranked_players', 'totals.last_score_at')
            // A game no cabinet can read the hiscores of is only worth listing for its scores (D74).
            ->where(fn (Builder $game) => $game->where('games.hiscores', true)->orWhereNotNull('totals.game_id'));
    }

    /**
     * The first games of a highlight, as the front draws them.
     *
     * @param  Builder<Game>  $query
     * @param  Collection<int, GamePopularity>  $popularity
     * @return list<array<string, mixed>>
     */
    private static function cards(Builder $query, int $limit, Collection $popularity): array
    {
        $games = $query->orderBy('games.description')->orderBy('games.romname')
            ->with(['catverCategory.parent', 'media' => fn ($media) => $media->where('type', 'screenshot')])
            ->limit($limit)
            ->get();
        $cards = [];
        foreach ($games as $game) {
            $cards[] = [
                ...self::summary($game),
                'ranked_players' => (int) ($game->getAttribute('ranked_players') ?? 0),
                'screenshot' => $game->media->first()?->hash,
                'popularity' => self::popularitySummary($popularity->get($game->id)),
            ];
        }

        return $cards;
    }

    /** @return array<int> */
    private static function myPlayerIds(Request $request): array
    {
        return array_map(intval(...), AuthenticateMember::member($request)->players()->pluck('players.id')->all());
    }

    /**
     * Orders by the place of the game in `$ids`, the games out of it last.
     *
     * @param  Builder<Game>  $query
     * @param  array<int, int|string>  $ids
     * @return Builder<Game>
     */
    private static function orderByIds(Builder $query, array $ids): Builder
    {
        // One binding: the ids as a PostgreSQL array literal.
        return $ids === []
            ? $query
            : $query->orderByRaw('array_position(?::bigint[], games.id) nulls last', ['{'.implode(',', array_map(intval(...), $ids)).'}']);
    }

    /**
     * What a list shows of a game's popularity (D76); null when nobody reported nor scored on it.
     *
     * @return array{index: float, label: string|null}|null
     */
    private static function popularitySummary(?GamePopularity $popularity): ?array
    {
        return $popularity === null ? null : ['index' => $popularity->index, 'label' => $popularity->label?->value];
    }

    /**
     * What a game's page shows of its popularity. Thumbs down are not given: `votes` minus
     * `thumbs_up` tells them, and the front only says how many cabinets liked the game (D77).
     *
     * @return array<string, mixed>|null
     */
    private static function popularityDetails(?GamePopularity $popularity): ?array
    {
        return $popularity === null ? null : [
            'index' => $popularity->index,
            'label' => $popularity->label?->value,
            'thumbs_up' => $popularity->thumbsUp,
            'votes' => $popularity->votes(),
            'cabinets' => $popularity->cabinets,
            'plays' => $popularity->plays,
        ];
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
