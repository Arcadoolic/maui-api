<?php

namespace App\Http\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception raised under /api as an RFC 9457 problem document
 * with a stable `code` extension (docs/DECISIONS.md D11).
 *
 * Never exposes exception messages or traces, whatever APP_DEBUG says:
 * details go to the logs through the regular exception reporting.
 */
final class ApiProblemRenderer
{
    public const CONTENT_TYPE = 'application/problem+json';

    /** @var array<int, string> */
    private const CODES_BY_STATUS = [
        Response::HTTP_UNAUTHORIZED => 'unauthenticated',
        Response::HTTP_FORBIDDEN => 'forbidden',
        Response::HTTP_NOT_FOUND => 'not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'validation_failed',
        Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
    ];

    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $exception instanceof ApiProblemException => self::make(
                $exception->status,
                $exception->problemCode,
                extra: $exception->detail === null ? [] : ['detail' => $exception->detail],
            ),
            $exception instanceof ValidationException => self::make(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                extra: ['errors' => $exception->errors()],
            ),
            $exception instanceof AuthenticationException => self::make(Response::HTTP_UNAUTHORIZED),
            $exception instanceof HttpExceptionInterface => self::make(
                $exception->getStatusCode(),
                headers: $exception->getHeaders(),
            ),
            default => self::make(Response::HTTP_INTERNAL_SERVER_ERROR),
        };
    }

    /**
     * @param  array<string, mixed>  $extra  Additional problem members (e.g. validation `errors`).
     * @param  array<string, string|string[]>  $headers
     */
    public static function make(int $status, ?string $code = null, array $extra = [], array $headers = []): JsonResponse
    {
        $problem = [
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'code' => $code ?? self::codeFor($status),
            ...$extra,
        ];

        return new JsonResponse($problem, $status, [...$headers, 'Content-Type' => self::CONTENT_TYPE]);
    }

    private static function codeFor(int $status): string
    {
        return self::CODES_BY_STATUS[$status] ?? ($status >= 500 ? 'server_error' : 'http_error');
    }
}
