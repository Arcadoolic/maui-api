<?php

namespace App\Filament\Resources\ServiceAccounts\Pages;

use App\Filament\Resources\ServiceAccounts\ServiceAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceAccounts extends ListRecords
{
    protected static string $resource = ServiceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
