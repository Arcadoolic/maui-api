<?php

use App\Http\Middleware\AuthenticateCabinet;
use App\Http\Problems\ApiProblemException;
use App\Http\Problems\ApiProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })->create();
