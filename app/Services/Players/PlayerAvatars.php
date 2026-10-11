<?php

namespace App\Services\Players;

use App\Models\Client;
use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Player avatars (docs/DECISIONS.md D53): a PNG sent by the cabinet the
 * player was created on, or set by an administrator (D83), kept on the
 * `local` disk, served to every cabinet that shows the player in a
 * leaderboard.
 */
final class PlayerAvatars
{
    public const DISK = 'local';

    /** Kilobytes. */
    public const MAX_SIZE = 256;

    public const MAX_DIMENSION = 1024;

    public function store(Client $client, Player $player, UploadedFile $avatar): Player
    {
        $this->put($player, (string) $avatar->get(), byAdmin: false);

        activity(PlayerRegistry::LOG_NAME)
            ->performedOn($player)
            ->causedBy($client)
            ->event('player.avatar_changed')
            ->log('player.avatar_changed');

        return $player;
    }

    /**
     * The avatar an administrator gives a player from the back office (D83):
     * any picture, made a square PNG within the limits of a cabinet's first
     * (AvatarImage, D84). Recorded with the logged-in administrator as causer.
     *
     * @throws ValidationException
     */
    public function storeByAdmin(Player $player, string $image): Player
    {
        $this->put($player, AvatarImage::normalize($image), byAdmin: true);

        activity(PlayerRegistry::LOG_NAME)
            ->performedOn($player)
            ->event('player.avatar_changed')
            ->log('player.avatar_changed');

        return $player;
    }

    private function put(Player $player, string $png, bool $byAdmin): void
    {
        Storage::disk(self::DISK)->put(self::path($player), $png);
        $player->forceFill(['avatar_hash' => hash('sha256', $png), 'avatar_by_admin' => $byAdmin])->save();
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
