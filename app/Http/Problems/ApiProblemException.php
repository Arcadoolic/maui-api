<?php

namespace App\Http\Problems;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business error rendered as a problem document with a specific `code`
 * (see docs/openapi.yaml, "Errors").
 */
final class ApiProblemException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly ?string $detail = null,
        /** @var array<string, mixed> Extra problem members, e.g. `attempts_left`. */
        public readonly array $extra = [],
    ) {
        parent::__construct($problemCode);
    }

    public static function unauthenticated(): self
    {
        return new self(Response::HTTP_UNAUTHORIZED, 'unauthenticated');
    }

    public static function clientDisabled(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'client_disabled', 'This client has been disabled by an administrator.');
    }

    public static function insufficientAbility(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'insufficient_ability');
    }

    public static function machineFingerprintMissing(): self
    {
        return new self(Response::HTTP_BAD_REQUEST, 'machine_fingerprint_missing', 'X-Maui-Machine must be 64 lowercase hexadecimal characters.');
    }

    public static function machineMismatch(): self
    {
        return new self(Response::HTTP_CONFLICT, 'machine_mismatch', 'These credentials are already used on another cabinet.');
    }

    public static function initialsTaken(): self
    {
        return new self(Response::HTTP_CONFLICT, 'initials_taken', 'These initials belong to another player.');
    }

    public static function playerNotFound(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'player_not_found');
    }

    public static function gameNotFound(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'game_not_found');
    }

    public static function mediaNotFound(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'media_not_found', 'This game has no such picture.');
    }

    public static function avatarNotFound(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'avatar_not_found', 'This player has no avatar.');
    }

    public static function playerDisabled(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'player_disabled', 'This player has been disabled by an administrator.');
    }

    public static function playerLocked(): self
    {
        return new self(Response::HTTP_LOCKED, 'player_locked', 'Too many wrong PINs: a new PIN must be issued from the cabinet this player was created on, or by an administrator.');
    }

    public static function notOriginCabinet(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN, 'not_origin_cabinet',
            'Only the cabinet this player was created on, or an administrator, can issue a new PIN.',
        );
    }

    public static function avatarNotFromOriginCabinet(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN, 'not_origin_cabinet',
            'Only the cabinet this player was created on can change its avatar.',
        );
    }

    public static function pinInvalid(int $attemptsLeft): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'pin_invalid', extra: ['attempts_left' => $attemptsLeft]);
    }

    public static function originNotAllowed(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'origin_not_allowed', 'This request does not come from the hiscores front.');
    }

    public static function memberDisabled(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'member_disabled', 'This member has been disabled by an administrator.');
    }

    public static function invitationUnavailable(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'invitation_unavailable', 'This invitation does not exist or can no longer be used.');
    }

    public static function playerAlreadyLinked(): self
    {
        return new self(Response::HTTP_CONFLICT, 'player_already_linked', 'This player is linked to another member.');
    }

    public static function memberHasPlayer(): self
    {
        return new self(Response::HTTP_CONFLICT, 'member_has_player', 'This member already has a player: unlink it first.');
    }
}
