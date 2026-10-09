<?php

namespace App\Http\Controllers\Front;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\ScoreEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The feed of the hiscores front: what the scores changed on the leaderboards
 * (D60), latest first. Unlike the bots' feed, it is a history: events too old
 * to be announced are part of it (docs/DECISIONS.md D67).
 */
final class EventsController
{
    public const MAX_EVENTS = 100;

    public const DEFAULT_EVENTS = 30;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The `cursor` of the previous page: events older than it.
            'before' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_EVENTS],
            'player' => ['sometimes', 'uuid'],
        ]);
        $limit = (int) ($validated['limit'] ?? self::DEFAULT_EVENTS);

        $query = self::visibleEvents()->orderByDesc('id')->limit($limit);
        if (isset($validated['before'])) {
            $query->where('id', '<', (int) $validated['before']);
        }
        if (isset($validated['player'])) {
            $query->where('player_id', Player::query()->where('uuid', Str::lower((string) $validated['player']))->value('id') ?? 0);
        }
        $events = $query->get();

        return new JsonResponse([
            'events' => $events->map(fn (ScoreEvent $event): array => $event->toApiArray())->all(),
            // Null once the oldest event has been sent.
            'cursor' => $events->count() === $limit ? $events->last()?->id : null,
        ]);
    }

    /**
     * Events the front shows: not retracted, of a player still public and active.
     *
     * @return Builder<ScoreEvent>
     */
    public static function visibleEvents(): Builder
    {
        return ScoreEvent::query()
            ->whereNull('retracted_at')
            ->whereHas('player', fn (Builder $player) => $player
                ->where('is_public', true)
                ->where('status', PlayerStatus::Active))
            ->with(['player', 'game']);
    }
}
