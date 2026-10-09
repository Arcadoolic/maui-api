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
            'events' => $events->map(fn (ScoreEvent $event): array => $event->toApiArray())->all(),
            // Where to read from next time: the last event sent, else the
            // cursor received, else the latest event there is.
            'cursor' => $events->last()->id ?? $after ?? (int) ScoreEvent::query()->max('id'),
        ]);
    }
}
