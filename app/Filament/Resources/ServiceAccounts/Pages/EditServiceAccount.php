<?php

namespace App\Filament\Resources\ServiceAccounts\Pages;

use App\Filament\Resources\ServiceAccounts\ServiceAccountResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditServiceAccount extends EditRecord
{
    protected static string $resource = ServiceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
