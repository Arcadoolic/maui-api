<?php

namespace App\Services\Players;

use App\Models\Client;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Back office operations on players, recorded in the audit log with the
 * admin as causer (docs/DECISIONS.md D48).
 */
final class PlayerAdministration
{
    /** Refused on every cabinet: no link, no change (scores hidden from Lot 2.3 on). */
    public function disable(Player $player): void
    {
        $player->disable();
        $this->audit($player, 'player.disabled');
    }

    public function enable(Player $player): void
    {
        $player->enable();
        $this->audit($player, 'player.enabled');
    }

    /** Clears the PIN lock; the PIN itself is unchanged. */
    public function unlock(Player $player): void
    {
        $player->unlock();
        $this->audit($player, 'player.unlocked');
    }

    /**
     * The PIN, for an admin helping a player who lost it (D49). Every reading
     * is recorded, never the PIN itself.
     */
    public function revealPin(Player $player): string
    {
        $this->audit($player, 'player.pin_viewed');

        return $player->pin;
    }

    /** A new PIN, which also unlocks the player. */
    public function regeneratePin(Player $player): string
    {
        $pin = $player->regeneratePin();
        $this->audit($player, 'player.pin_regenerated');

        return $pin;
    }

    /**
     * The cabinet that may issue a new PIN (D54): one of the cabinets the
     * player is linked to, or none, which leaves it to admins. For a player
     * whose origin cabinet is gone, sold, or was guessed wrong by the
     * migration (oldest link).
     */
    public function setOrigin(Player $player, ?Client $origin): void
    {
        if ($origin !== null && ! $player->clients()->whereKey($origin->id)->exists()) {
            throw new InvalidArgumentException('The origin cabinet must be one the player is linked to.');
        }

        $from = $player->originClient?->name;
        $player->forceFill(['origin_client_id' => $origin?->id])->save();
        $player->unsetRelation('originClient');
        $this->audit($player, 'player.origin_changed', ['from' => $from, 'to' => $origin?->name]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(Player $player, string $event, array $properties = []): void
    {
        $admin = Auth::user();

        activity(PlayerRegistry::LOG_NAME)
            ->performedOn($player)
            ->causedBy($admin instanceof User ? $admin : null)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
