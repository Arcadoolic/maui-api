<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\PutCatalogGamesRequest;
use App\Services\Catalog\CatalogImporter;
use Illuminate\Http\JsonResponse;

final class CatalogGamesController
{
    public function __invoke(PutCatalogGamesRequest $request, CatalogImporter $importer): JsonResponse
    {
        return new JsonResponse($importer->import($request->games())->toArray());
    }
}
