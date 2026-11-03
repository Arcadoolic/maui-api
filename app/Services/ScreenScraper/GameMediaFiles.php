<?php

namespace App\Services\ScreenScraper;

use App\Http\Problems\ApiProblemException;
use App\Models\Game;
use App\Models\GameMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The stored pictures of the games, as the hiscores front (D68), the cabinets and the pack
 * manager (D99) read them: one rule chose them, every reader shows the same.
 */
final class GameMediaFiles
{
    public const ROMNAME = '/^[a-z0-9_]{1,32}$/';

    /** What a cabinet shows of a game: one of each, the first (D99). */
    public const CABINET_TYPES = ['flyer', 'logo', 'marquee'];

    /**
     * For each game, the hash of its first marquee, flyer and logo.
     *
     * @return array<string, array<string, string>>
     */
    public function cabinetHashes(): array
    {
        $games = [];
        GameMedia::query()
            ->join('games', 'games.id', '=', 'game_media.game_id')
            ->where('game_media.position', 0)
            ->whereIn('game_media.type', self::CABINET_TYPES)
            ->orderBy('games.romname')->orderBy('game_media.type')
            ->select(['games.romname', 'game_media.type', 'game_media.hash'])
            ->toBase()
            ->each(function (object $row) use (&$games): void {
                $games[(string) $row->romname][(string) $row->type] = (string) $row->hash;
            });

        return $games;
    }

    /**
     * The hash of each type's first picture of one game.
     *
     * @return array<string, string>
     */
    public function hashes(Game $game): array
    {
        return GameMedia::query()->where('game_id', $game->id)->where('position', 0)
            ->orderBy('type')->pluck('hash', 'type')->all();
    }

    /** A picture, with its hash as ETag; `$position` picks one of its flyers (D73) or logos (D88). */
    public function response(Request $request, string $romname, string $type, mixed $position = '0'): Response
    {
        $media = preg_match(self::ROMNAME, $romname) === 1 && isset(GameMedia::TYPES[$type]) && is_string($position) && preg_match('/^\d{1,2}$/', $position) === 1
            ? GameMedia::query()->where('type', $type)->where('position', (int) $position)
                ->whereHas('game', fn (Builder $game) => $game->where('romname', $romname))->first()
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
}
