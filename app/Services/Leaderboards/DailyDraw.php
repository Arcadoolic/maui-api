<?php

namespace App\Services\Leaderboards;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A weighted draw without replacement, the same all day (docs/DECISIONS.md D95, D96): each item
 * gets u^(1/weight), u drawn from the day and the item's seed, the greatest first. Then at most
 * `$perOwner` items of the same owner come first, and the rest of the draw follows, so that one
 * owner does not fill a row while others wait.
 */
final class DailyDraw
{
    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem, CarbonInterface): float  $weight  Given the start of the day, more than 0.
     * @param  callable(TItem): string  $seed  The same item, the same seed.
     * @param  callable(TItem): int  $owner
     * @return Collection<int, TItem> In the order drawn.
     */
    public static function draw(Collection $items, CarbonInterface $day, callable $weight, callable $seed, callable $owner, int $perOwner): Collection
    {
        // From the start of the day: the weights, and so the draw, hold all day.
        $today = $day->copy()->startOfDay();
        $drawn = $items->sortByDesc(fn (mixed $item): float => log(self::uniform($today->toDateString().'|'.$seed($item))) / $weight($item, $today));

        $perOwners = [];
        $varied = $drawn->filter(function (mixed $item) use (&$perOwners, $owner, $perOwner): bool {
            $perOwners[$owner($item)] = ($perOwners[$owner($item)] ?? 0) + 1;

            return $perOwners[$owner($item)] <= $perOwner;
        });

        return $varied->concat($drawn->diffKeys($varied))->values();
    }

    /** A number in ]0, 1[ drawn from `$seed`, the same for the same seed. */
    private static function uniform(string $seed): float
    {
        return (hexdec(substr(hash('sha256', $seed), 0, 13)) + 1) / (16 ** 13 + 1);
    }
}
