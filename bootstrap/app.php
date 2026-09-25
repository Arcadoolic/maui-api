<?php

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Middleware\SecureInvitationPages;
use App\Http\Problems\ApiProblemException;
use App\Http\Problems\ApiProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['cabinet' => AuthenticateCabinet::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected business errors (auth, binding...): logged where they happen, not as errors.
        $exceptions->dontReport(ApiProblemException::class);
        $exceptions->render(new ApiProblemRenderer);
        // CSRF (419), rate limit (429) and server errors on invitation pages
        // must not be cached or indexed either: their URL holds the secret.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request): Response => SecureInvitationPages::appliesTo($request)
            ? SecureInvitationPages::withPrivacyHeaders($response)
            : $response);
    })->create();
