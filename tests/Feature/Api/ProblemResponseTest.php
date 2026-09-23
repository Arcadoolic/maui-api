<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::get('get-only', fn () => 'ok');
        Route::post('validation', fn (Request $request) => $request->validate(['os' => 'required']));
        Route::get('unauthenticated', fn () => throw new AuthenticationException);
        Route::get('forbidden', fn () => throw new AccessDeniedHttpException);
        Route::get('crash', fn () => throw new RuntimeException('db password is hunter2'));
        Route::get('throttled', fn () => 'ok')->middleware('throttle:1,1');
    });
});

it('renders an unknown API route as a not_found problem', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'code' => 'not_found',
        ]);
});

it('renders a wrong HTTP method as a method_not_allowed problem', function () {
    $this->postJson('/api/v1/_test/get-only')
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');
});

it('renders validation errors as a validation_failed problem with field errors', function () {
    $this->postJson('/api/v1/_test/validation', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['errors' => ['os']]);
});

it('renders an authentication failure as an unauthenticated problem', function () {
    $this->getJson('/api/v1/_test/unauthenticated')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('renders an access denied error as a forbidden problem', function () {
    $this->getJson('/api/v1/_test/forbidden')
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

it('renders a rate limit hit as a rate_limited problem with Retry-After', function () {
    $this->getJson('/api/v1/_test/throttled')->assertOk();

    $this->getJson('/api/v1/_test/throttled')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited');
});

it('never leaks exception details, even in debug mode', function () {
    config(['app.debug' => true]);

    $response = $this->getJson('/api/v1/_test/crash')
        ->assertInternalServerError()
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Internal Server Error',
            'status' => 500,
            'code' => 'server_error',
        ]);

    expect($response->getContent())
        ->not->toContain('hunter2')
        ->not->toContain('RuntimeException');
});

it('renders API problems even when the client does not ask for JSON', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('leaves web routes to the default Laravel rendering', function () {
    $response = $this->get('/does-not-exist')->assertNotFound();

    expect($response->headers->get('Content-Type'))->not->toBe('application/problem+json');

    $this->get('/up')->assertOk();
});
