<?php

namespace App\Filament\Resources\ServiceAccounts\Pages;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\ServiceAccounts\ServiceAccountResource;

/**
 * The same page and operations as a cabinet's: each action shows for the
 * types it is for (a token here, no invitation nor machine binding).
 */
class ViewServiceAccount extends ViewClient
{
    protected static string $resource = ServiceAccountResource::class;
}
