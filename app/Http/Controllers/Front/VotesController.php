<?php

namespace App\Http\Controllers\Front;

use App\Http\Middleware\AuthenticateMember;
use App\Http\Problems\ApiProblemException;
use App\Models\Game;
use App\Models\GameOpinion;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The votes of the member's player on the games, as on a cabinet: thumbs up, neutral or thumbs
 * down, on every game whose hiscores a cabinet can read (docs/DECISIONS.md D86).
 */
final class VotesController
{
    private const ROMNAME = '/^[a-z0-9_]{1,32}$/';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'vote' => ['sometimes', Rule::in(['up', 'down', 'none'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.GamesController::MAX_PER_PAGE],
        ]);
        $perPage = (int) ($filters['per_page'] ?? GamesController::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);
        $player = self::player($request);

        $query = Game::query()
            ->where('games.hiscores', true)
            ->leftJoin('player_opinions as mine', fn ($join) => $join
                ->on('mine.game_id', '=', 'games.id')
                ->where('mine.player_id', $player->id))
            ->select('games.*', 'mine.vote as my_vote');
        if (filled($filters['q'] ?? null)) {
            $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn (Builder $game) => $game
                ->where('games.description', 'ilike', $like)
                ->orWhere('games.romname', 'ilike', $like));
        }
        // What each choice of the filter would show, the search applied.
        $counts = (clone $query)->toBase()
            ->select([])
            ->selectRaw('count(*) as all_games')
            ->selectRaw('count(*) filter (where mine.vote = ?) as up', [GameOpinion::VOTE_UP])
            ->selectRaw('count(*) filter (where mine.vote = ?) as down', [GameOpinion::VOTE_DOWN])
            ->selectRaw('count(*) filter (where mine.vote is null) as none')
            ->first();
        match ($filters['vote'] ?? null) {
            'up' => $query->where('mine.vote', GameOpinion::VOTE_UP),
            'down' => $query->where('mine.vote', GameOpinion::VOTE_DOWN),
            'none' => $query->whereNull('mine.vote'),
            default => null,
        };

        $total = (clone $query)->count('games.id');
        $games = $query->orderBy('games.description')->orderBy('games.romname')
            ->with('catverCategory.parent')
            ->forPage($page, $perPage)
            ->get();

        return new JsonResponse([
            'games' => $games->map(fn (Game $game): array => [
                ...GamesController::summary($game),
                'my_vote' => (int) ($game->getAttribute('my_vote') ?? GameOpinion::VOTE_NEUTRAL),
            ])->all(),
            'counts' => [
                'all' => (int) ($counts->all_games ?? 0),
                'up' => (int) ($counts->up ?? 0),
                'down' => (int) ($counts->down ?? 0),
                'none' => (int) ($counts->none ?? 0),
            ],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /** Sets the vote; a neutral one forgets it, as neutral is no vote. */
    public function update(Request $request, string $romname): JsonResponse
    {
        $vote = (int) $request->validate([
            'vote' => ['required', 'integer', Rule::in([GameOpinion::VOTE_DOWN, GameOpinion::VOTE_NEUTRAL, GameOpinion::VOTE_UP])],
        ])['vote'];
        $player = self::player($request);
        if (! $player->isActive()) {
            throw ApiProblemException::playerDisabled();
        }
        $game = preg_match(self::ROMNAME, $romname) === 1
            ? Game::query()->where('romname', $romname)->where('hiscores', true)->first()
            : null;
        if ($game === null) {
            throw ApiProblemException::gameNotFound();
        }

        if ($vote === GameOpinion::VOTE_NEUTRAL) {
            $player->opinions()->where('game_id', $game->id)->delete();
        } else {
            $opinion = $player->opinions()->firstOrNew(['game_id' => $game->id]);
            // The date of the vote moves only when the vote does.
            if (! $opinion->exists || $opinion->vote !== $vote) {
                $opinion->fill(['vote' => $vote, 'voted_at' => now()])->save();
            }
        }

        return new JsonResponse(['romname' => $game->romname, 'vote' => $vote]);
    }

    /** The vote of the member's player on a game's page: null without one, or where nobody can score. */
    public static function myVote(Request $request, Game $game): ?int
    {
        $player = AuthenticateMember::member($request)->player();
        if ($player === null || ! $game->hiscores) {
            return null;
        }

        return (int) ($player->opinions()->where('game_id', $game->id)->value('vote') ?? GameOpinion::VOTE_NEUTRAL);
    }

    private static function player(Request $request): Player
    {
        return AuthenticateMember::member($request)->player() ?? throw ApiProblemException::playerNotFound();
    }
}
