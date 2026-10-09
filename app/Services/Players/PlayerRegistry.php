<?php

namespace App\Services\Players;

use App\Http\Problems\ApiProblemException;
use App\Models\Client;
use App\Models\Member;
use App\Models\Player;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Players as cabinets see them: initials reserved fleet-wide, linked to a
 * cabinet at creation or with the PIN (docs/DECISIONS.md D48). Every change
 * is recorded in the audit log with the cabinet as causer (the member, when
 * it comes from the hiscores front); PINs never are.
 */
final class PlayerRegistry
{
    public const LOG_NAME = 'players';

    /** `free`, `taken` or `disabled`. */
    public function availability(string $pseudo3): string
    {
        $player = Player::query()->where('pseudo_3', $pseudo3)->first();

        return match (true) {
            $player === null => 'free',
            ! $player->isActive() => 'disabled',
            default => 'taken',
        };
    }

    /**
     * Creates the player and links it to the cabinet (D4: synchronous, the
     * initials are reserved at once). The PIN is returned in plain text once.
     *
     * @return array{0: Player, 1: string}
     */
    public function register(Client $client, string $pseudo3, bool $isPublic): array
    {
        $pin = Player::generatePin();

        try {
            $player = DB::transaction(function () use ($client, $pseudo3, $isPublic, $pin): Player {
                $player = new Player(['pseudo_3' => $pseudo3, 'is_public' => $isPublic]);
                $player->pin = $pin;
                $player->origin_client_id = $client->id;
                $player->save();
                $player->clients()->attach($client, ['linked_at' => now()]);

                return $player;
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblemException::initialsTaken();
        }

        $this->audit($player, $client, 'player.created', ['is_public' => $isPublic]);

        return [$player, $pin];
    }

    /**
     * Links an existing player to the cabinet. Wrong PINs are counted, and
     * lock the player after Player::MAX_PIN_ATTEMPTS in a row.
     */
    public function link(Client $client, string $pseudo3, string $pin): Player
    {
        return $this->linkWithPin($client, $pseudo3, $pin, 'player.linked', function (Player $player) use ($client): string {
            $player->clients()->syncWithoutDetaching([$client->id => ['linked_at' => now()]]);

            return 'linked';
        });
    }

    /**
     * Links an existing player to a member of the hiscores front, with the
     * same PIN and the same lock as on a cabinet (D66). One player per member
     * and one member per player (D71): the PIN is checked first, so that only
     * its holder learns the player is taken.
     */
    public function linkMember(Member $member, string $pseudo3, string $pin): Player
    {
        return $this->linkWithPin($member, $pseudo3, $pin, 'player.member_linked', function (Player $player) use ($member): string {
            $holder = $player->members()->first();
            if ($holder !== null) {
                return $holder->is($member) ? 'linked' : 'taken';
            }
            // Locked with the player's row: two requests of the member cannot both pass.
            if (Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail()->players()->exists()) {
                return 'member_has_player';
            }
            $player->members()->attach($member, ['linked_at' => now()]);

            return 'linked';
        });
    }

    /** The player stays as it is on its cabinets. */
    public function unlinkMember(Member $member, Player $player): void
    {
        $player->members()->detach($member);
        $this->audit($player, $member, 'player.member_unlinked');
    }

    /**
     * @param  Closure(Player): string  $attach  Links the player once the PIN is right: `linked`, or why it cannot be.
     */
    private function linkWithPin(Client|Member $causer, string $pseudo3, string $pin, string $event, Closure $attach): Player
    {
        // The failure count must be committed before the error is thrown.
        [$player, $outcome] = DB::transaction(function () use ($pseudo3, $pin, $attach): array {
            $player = Player::query()->where('pseudo_3', $pseudo3)->lockForUpdate()->first();

            if ($player === null) {
                return [null, 'not_found'];
            }
            if (! $player->isActive()) {
                return [$player, 'disabled'];
            }
            if ($player->isLocked()) {
                return [$player, 'locked'];
            }
            if (! $player->pinMatches($pin)) {
                $player->pin_failed_attempts++;
                if ($player->pin_failed_attempts >= Player::MAX_PIN_ATTEMPTS) {
                    $player->pin_locked_at = now();
                }
                $player->save();

                return [$player, $player->isLocked() ? 'now_locked' : 'pin_invalid'];
            }

            $player->forceFill(['pin_failed_attempts' => 0])->save();

            return [$player, $attach($player)];
        });

        if ($outcome === 'now_locked' && $player instanceof Player) {
            $this->audit($player, $causer, 'player.locked');
        }
        if ($outcome === 'linked' && $player instanceof Player) {
            $this->audit($player, $causer, $event);
        }

        return match ($outcome) {
            'not_found' => throw ApiProblemException::playerNotFound(),
            'disabled' => throw ApiProblemException::playerDisabled(),
            'locked', 'now_locked' => throw ApiProblemException::playerLocked(),
            'pin_invalid' => throw ApiProblemException::pinInvalid(Player::MAX_PIN_ATTEMPTS - ($player->pin_failed_attempts ?? 0)),
            'taken' => throw ApiProblemException::playerAlreadyLinked(),
            'member_has_player' => throw ApiProblemException::memberHasPlayer(),
            default => $player ?? throw ApiProblemException::playerNotFound(),
        };
    }

    public function setVisibility(Client $client, Player $player, bool $isPublic): Player
    {
        $this->ensureActive($player);

        if ($player->is_public !== $isPublic) {
            $player->forceFill(['is_public' => $isPublic])->save();
            $this->audit($player, $client, 'player.visibility_changed', ['is_public' => $isPublic]);
        }

        return $player;
    }

    /** A new PIN, from a cabinet the player is linked to. Unlocks the player. */
    public function regeneratePin(Client $client, Player $player): string
    {
        $this->ensureActive($player);
        // Only the cabinet the player was created on (D54): another one could otherwise take the
        // PIN away from the player, or link it wherever it wants.
        if (! $player->isOrigin($client)) {
            throw ApiProblemException::notOriginCabinet();
        }

        $pin = $player->regeneratePin();
        $this->audit($player, $client, 'player.pin_regenerated');

        return $pin;
    }

    /** The player stays, with its initials and its other cabinets. */
    public function unlink(Client $client, Player $player): void
    {
        $player->clients()->detach($client);
        $this->audit($player, $client, 'player.unlinked');
    }

    private function ensureActive(Player $player): void
    {
        if (! $player->isActive()) {
            throw ApiProblemException::playerDisabled();
        }
    }

    /**
     * @param  array<string, mixed>  $properties  Never put a PIN here.
     */
    private function audit(Player $player, Client|Member $causer, string $event, array $properties = []): void
    {
        $by = $causer instanceof Client ? ['client' => $causer->name] : ['member' => $causer->username];

        activity(self::LOG_NAME)
            ->performedOn($player)
            ->causedBy($causer)
            ->event($event)
            ->withProperties([...$by, ...$properties])
            ->log($event);
    }
}
