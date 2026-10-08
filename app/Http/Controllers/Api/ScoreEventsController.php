<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlayerStatus;
use App\Models\ScoreEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Score events, for the bots that announce them (docs/DECISIONS.md D60). */
final class ScoreEventsController
{
    public const MAX_EVENTS = 100;

    public const DEFAULT_EVENTS = 50;

    /**
     * Events after a cursor, oldest first; without a cursor, the latest
     * ones. Only what may be announced: not too old, not retracted, of a
     * player still public and active.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_EVENTS],
        ]);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_EVENTS);
        $after = isset($validated['after']) ? (int) $validated['after'] : null;

        $query = ScoreEvent::query()
            ->where('announceable', true)
            ->whereNull('retracted_at')
            ->whereHas('player', fn (Builder $player) => $player
                ->where('is_public', true)
                ->where('status', PlayerStatus::Active))
            ->with(['player', 'game'])
            ->limit($limit);
        $events = $after === null
            ? $query->orderByDesc('id')->get()->reverse()->values()
            : $query->where('id', '>', $after)->orderBy('id')->get();

        return new JsonResponse([
            'events' => $events->map(fn (ScoreEvent $event): array => [
                'id' => $event->id,
                'uuid' => $event->uuid,
                'movement' => $event->movement,
                'flavors' => $event->flavors,
                'importance' => $event->importance,
                'message' => $event->message,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'player' => [
                    'id' => $event->player->uuid,
                    'pseudo_3' => $event->player->pseudo_3,
                    'avatar' => $event->player->avatar_hash,
                ],
                'game' => [
                    'romname' => $event->game->romname,
                    'description' => $event->game->description,
                    'manufacturer' => $event->game->manufacturer,
                ],
                'table' => $event->table,
                'score' => $event->score,
                'rank_before' => $event->rank_before,
                'rank_after' => $event->rank_after,
                'facts' => $event->facts,
            ])->all(),
            // Where to read from next time: the last event sent, else the
            // cursor received, else the latest event there is.
            'cursor' => $events->last()->id ?? $after ?? (int) ScoreEvent::query()->max('id'),
        ]);
    }
}
