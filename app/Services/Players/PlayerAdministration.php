<?php

namespace App\Services\Players;

use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

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

    private function audit(Player $player, string $event): void
    {
        $admin = Auth::user();

        activity(PlayerRegistry::LOG_NAME)
            ->performedOn($player)
            ->causedBy($admin instanceof User ? $admin : null)
            ->event($event)
            ->log($event);
    }
}
