<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Front\EventsController;
use App\Models\ScoreEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Score events for the cabinets, which show their sentences while nobody
 * plays (docs/DECISIONS.md D82): the latest ones, newest first.
 */
final class CabinetEventsController
{
    public const MAX_EVENTS = 50;

    public const DEFAULT_EVENTS = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_EVENTS],
            'min_importance' => ['sometimes', 'integer', 'min:1', 'max:3'],
        ]);

        // As on the front: a score sent late is still news here, unlike for
        // a bot. Only its age counts.
        $query = EventsController::visibleEvents()
            ->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? self::DEFAULT_EVENTS));
        $days = (int) config('hiscores.events.cabinet_days');
        if ($days > 0) {
            $query->where('occurred_at', '>=', now()->subDays($days));
        }
        if (isset($validated['min_importance'])) {
            $query->where('importance', '>=', (int) $validated['min_importance']);
        }

        return new JsonResponse([
            'events' => $query->get()->map(fn (ScoreEvent $event): array => $event->toApiArray())->all(),
        ]);
    }
}
