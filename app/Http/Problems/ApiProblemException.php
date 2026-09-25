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
}
