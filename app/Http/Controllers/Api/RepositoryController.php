<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

/**
 * Announces the repository URL of this server, so that the cabinet never
 * sends its credentials to a URL typed by hand (docs/DECISIONS.md D46).
 */
final class RepositoryController
{
    public function __invoke(): JsonResponse
    {
        $url = config('maui.repository_url');

        return new JsonResponse([
            'url' => is_string($url) && $url !== '' ? rtrim($url, '/') : null,
        ]);
    }
}
