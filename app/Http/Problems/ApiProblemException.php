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

    public static function playerDisabled(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'player_disabled', 'This player has been disabled by an administrator.');
    }

    public static function playerLocked(): self
    {
        return new self(Response::HTTP_LOCKED, 'player_locked', 'Too many wrong PINs: a new PIN must be issued from a cabinet of this player.');
    }

    public static function pinInvalid(int $attemptsLeft): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'pin_invalid', extra: ['attempts_left' => $attemptsLeft]);
    }
}
