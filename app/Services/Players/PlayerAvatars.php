<?php

namespace App\Services\Players;

use App\Models\Client;
use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Player avatars (docs/DECISIONS.md D53): a PNG sent by a cabinet of the
 * player, kept on the `local` disk, served to every cabinet that shows the
 * player in a leaderboard.
 */
final class PlayerAvatars
{
    public const DISK = 'local';

    /** Kilobytes. */
    public const MAX_SIZE = 256;

    public const MAX_DIMENSION = 1024;

    public function store(Client $client, Player $player, UploadedFile $avatar): Player
    {
        $content = (string) $avatar->get();
        Storage::disk(self::DISK)->put(self::path($player), $content);
        $player->forceFill(['avatar_hash' => hash('sha256', $content)])->save();

        activity(PlayerRegistry::LOG_NAME)
            ->performedOn($player)
            ->causedBy($client)
            ->event('player.avatar_changed')
            ->log('player.avatar_changed');

        return $player;
    }

    public static function path(Player $player): string
    {
        return "avatars/{$player->uuid}.png";
    }

    public static function exists(Player $player): bool
    {
        return $player->avatar_hash !== null && Storage::disk(self::DISK)->exists(self::path($player));
    }
}
