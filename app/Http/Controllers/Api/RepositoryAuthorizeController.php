<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Response;

/**
 * forward_auth target of the repository's Caddy: reaching this controller
 * means the `repository` middleware accepted the request.
 */
final class RepositoryAuthorizeController
{
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
