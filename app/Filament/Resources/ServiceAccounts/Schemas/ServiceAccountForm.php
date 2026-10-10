<?php

namespace App\Filament\Resources\ServiceAccounts\Schemas;

use App\Enums\ClientType;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ServiceAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // No arcade name here: the admin says what the account does (D39).
                TextInput::make('name')
                    ->helperText(__('Describes what the account does, e.g. catalog_importer.'))
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z0-9]+(_[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true),
                Select::make('type')
                    ->options(collect(ClientType::cases())
                        ->filter(fn (ClientType $type): bool => $type->isService())
                        ->mapWithKeys(fn (ClientType $type): array => [$type->value => $type->getLabel()])
                        ->all())
                    ->default(ClientType::Service->value)
                    ->required()
                    // Abilities depend on the type: it cannot change once created.
                    ->disabledOn('edit'),
                ...ClientForm::ownerFields(__('Person responsible for this account, for traceability.')),
            ]);
    }
}
