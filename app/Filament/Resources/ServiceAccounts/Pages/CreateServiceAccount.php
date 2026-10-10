<?php

namespace App\Filament\Resources\ServiceAccounts\Pages;

use App\Filament\Resources\ServiceAccounts\ServiceAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceAccount extends CreateRecord
{
    protected static string $resource = ServiceAccountResource::class;
}
