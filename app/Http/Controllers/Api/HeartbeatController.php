<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\AuthenticateCabinet;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class HeartbeatController
{
    public function __invoke(Request $request): Response
    {
        AuthenticateCabinet::client($request)->recordHeartbeat();

        return response()->noContent();
    }
}
