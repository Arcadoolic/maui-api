<?php

namespace App\Http\Controllers\Front;

use App\Http\Middleware\AuthenticateMember;
use App\Http\Problems\ApiProblemException;
use App\Models\Game;
use App\Models\Member;
use App\Models\Player;
use App\Models\Score;
use App\Services\Leaderboards\GlobalRanking;
use App\Services\Leaderboards\Leaderboards;
use App\Services\Leaderboards\Rankings;
use App\Services\Leaderboards\Standing;
use App\Services\Players\PlayerAvatars;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Players of the hiscores front (docs/DECISIONS.md D67): the public and
 * active ones, as on the shared leaderboards, and the member's own players
 * even when they are private.
 */
final class PlayersController
{
    public function __construct(private readonly Rankings $rankings, private readonly GlobalRanking $globalRanking) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['sometimes', 'string', 'max:3']])['q'] ?? null;

        $players = Player::query()
            ->joinSub($this->rankings->playerTotals(), 'totals', 'totals.player_id', '=', 'players.id')
            ->when(filled($q), fn ($query) => $query->where('players.pseudo_3', 'like', Str::upper(addcslashes((string) $q, '%_\\')).'%'))
            ->orderByDesc('totals.crowns')
            ->orderByDesc('totals.podiums')
            ->orderByDesc('totals.games')
            ->orderBy('players.pseudo_3')
            ->get(['players.*', 'totals.games', 'totals.crowns', 'totals.podiums', 'totals.beaten', 'totals.last_score_at']);

        return new JsonResponse([
            'players' => $players->map(fn (Player $player): array => [
                ...GamesController::player($player),
                ...self::totals($player),
            ])->all(),
        ]);
    }

    public function show(Request $request, string $player): JsonResponse
    {
        $member = AuthenticateMember::member($request);
        $found = $this->shownPlayer($member, $player);
        $mine = $member->players()->whereKey($found->id)->exists();

        $totals = $this->rankings->playerTotals()->where('ranked.player_id', $found->id)->first();
        $rows = $this->rankings->rows()->where('ranked.player_id', $found->id)->get();
        // A private player is on no leaderboard: its bests are listed without a rank.
        if ($rows->isEmpty() && ! $found->is_public) {
            $rows = Score::query()->visible()->where('player_id', $found->id)
                ->select('scores.*')
                ->distinct(['game_id', 'table'])
                ->orderBy('game_id')->orderBy('table')->orderByDesc('score')->orderBy('achieved_at')
                ->toBase()->get();
        }
        $games = Game::query()->whereIn('id', $rows->pluck('game_id'))->get()->keyBy('id');
        // Its place on the global podium, and what each leaderboard brings to it (D70).
        $standing = $this->globalRanking->standings()->first(fn (Standing $standing): bool => $standing->playerId === $found->id);
        $counted = [];
        foreach ($standing->results ?? [] as $result) {
            $counted[$result['game_id'].'/'.$result['table']] = $result['counted'];
        }
        $neighbours = Player::query()
            ->whereIn('id', $rows->pluck('above_player_id')->merge($rows->pluck('below_player_id'))->filter()->unique())
            ->get()->keyBy('id');
        $neighbour = fn (object $row, string $side): ?array => ($row->{$side.'_player_id'} ?? null) === null ? null : [
            'player' => GamesController::player($neighbours->get($row->{$side.'_player_id'})),
            'score' => (int) $row->{$side.'_score'},
        ];

        return new JsonResponse([
            'player' => [
                ...GamesController::player($found),
                'is_public' => $found->is_public,
                'is_mine' => $mine,
            ],
            'stats' => [
                'games' => (int) ($totals->games ?? 0),
                'crowns' => (int) ($totals->crowns ?? 0),
                'podiums' => (int) ($totals->podiums ?? 0),
                'beaten' => (int) ($totals->beaten ?? 0),
                'last_score_at' => GamesController::iso($totals->last_score_at ?? null),
                'points' => $standing->points ?? 0,
                'global_rank' => $standing?->rank,
            ],
            'bests' => $rows
                ->sortBy(fn (object $row): string => Str::lower($games->get($row->game_id)->description ?? '').'/'.$row->table)
                ->values()
                ->map(fn (object $row): array => [
                    'game' => [
                        'romname' => (string) $games->get($row->game_id)?->romname,
                        'description' => (string) $games->get($row->game_id)?->description,
                    ],
                    'table' => (string) $row->table,
                    'score' => (int) $row->score,
                    'rank' => isset($row->rank) ? (int) $row->rank : null,
                    'players' => isset($row->players) ? (int) $row->players : null,
                    'achieved_at' => GamesController::iso($row->achieved_at),
                    // Points of this leaderboard on the global podium; `counted`: among the best results that make the total.
                    'points' => isset($row->rank) ? GlobalRanking::points((int) $row->rank, (int) $row->players) : null,
                    'counted' => $counted[$row->game_id.'/'.$row->table] ?? false,
                    // The next rank to take, and who is closest behind: null at either end.
                    'above' => $neighbour($row, 'above'),
                    'below' => $neighbour($row, 'below'),
                ])->all(),
            // Days (UTC) with at least one personal best, oldest first.
            'activity' => $this->ownScores($found)
                ->selectRaw("to_char(scores.achieved_at at time zone 'UTC', 'YYYY-MM-DD') as day, count(*) as bests")
                ->groupBy('day')->orderBy('day')->toBase()->get()
                ->map(fn (object $row): array => ['date' => (string) $row->day, 'bests' => (int) $row->bests])->all(),
        ]);
    }

    /**
     * The personal bests of a player on a game, oldest first: each one beat
     * the previous (D50). With the scores to reach: the leader's, and the
     * rank above the player's.
     */
    public function history(Request $request, string $player, string $romname): JsonResponse
    {
        $found = $this->shownPlayer(AuthenticateMember::member($request), $player);
        $table = $request->validate(['table' => ['sometimes', 'string', 'regex:/^[a-z0-9_]{1,32}$/']])['table'] ?? Score::DEFAULT_TABLE;
        $game = preg_match('/^[a-z0-9_]{1,32}$/', $romname) === 1 ? Game::query()->where('romname', $romname)->first() : null;
        if ($game === null) {
            throw ApiProblemException::gameNotFound();
        }

        $history = $this->ownScores($found)
            ->where('scores.game_id', $game->id)->where('scores.table', $table)
            ->orderBy('scores.achieved_at')->orderBy('scores.score')
            ->get(['scores.*']);
        $board = $this->rankings->rows()->where('ranked.game_id', $game->id)->where('ranked.table', $table);
        $own = (clone $board)->where('ranked.player_id', $found->id)->first();
        $leader = (clone $board)->where('ranked.rank', 1)->first();
        $players = Player::query()->whereIn('id', array_filter([$leader->player_id ?? null, $own->above_player_id ?? null]))->get()->keyBy('id');

        return new JsonResponse([
            'game' => ['romname' => $game->romname, 'description' => $game->description],
            'table' => $table,
            'rank' => $own === null ? null : (int) $own->rank,
            'players' => $own === null ? null : (int) $own->players,
            'history' => $history->map(fn (Score $score): array => [
                'score' => $score->score,
                'achieved_at' => $score->achieved_at->toIso8601String(),
            ])->all(),
            'leader' => $leader === null ? null : [
                'player' => GamesController::player($players->get($leader->player_id)),
                'score' => (int) $leader->score,
            ],
            'above' => $own === null || $own->above_player_id === null ? null : [
                'player' => GamesController::player($players->get($own->above_player_id)),
                'score' => (int) $own->above_score,
            ],
        ]);
    }

    /**
     * Scores of a player the member may see: the visible ones, or, for a
     * private player (the member's own), those not hidden.
     *
     * @return Builder<Score>
     */
    private function ownScores(Player $player): Builder
    {
        return ($player->is_public ? Leaderboards::visibleScores() : Score::query()->visible())
            ->where('scores.player_id', $player->id);
    }

    /** The PNG of a player the member may see, with its hash as ETag (D53). */
    public function avatar(Request $request, string $player): Response
    {
        $found = $this->shownPlayer(AuthenticateMember::member($request), $player);
        if (! PlayerAvatars::exists($found)) {
            throw ApiProblemException::avatarNotFound();
        }

        $response = Storage::disk(PlayerAvatars::DISK)->response(PlayerAvatars::path($found), null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache',
        ]);
        $response->setEtag((string) $found->avatar_hash);
        $response->isNotModified($request);

        return $response;
    }

    /** Public and active, or one of the member's own players. */
    private function shownPlayer(Member $member, string $id): Player
    {
        $found = Str::isUuid($id) ? Player::query()->where('uuid', Str::lower($id))->first() : null;
        if ($found === null || ! $found->isActive()) {
            throw ApiProblemException::playerNotFound();
        }
        if (! $found->is_public && ! $member->players()->whereKey($found->id)->exists()) {
            throw ApiProblemException::playerNotFound();
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private static function totals(Player $player): array
    {
        return [
            'games' => (int) $player->getAttribute('games'),
            'crowns' => (int) $player->getAttribute('crowns'),
            'podiums' => (int) $player->getAttribute('podiums'),
            'beaten' => (int) $player->getAttribute('beaten'),
            'last_score_at' => GamesController::iso($player->getAttribute('last_score_at')),
        ];
    }
}
