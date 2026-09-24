<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Cabinets get a generated arcade name (the owner can draw another
                // one); service accounts get a descriptive name from the admin (D39).
                TextInput::make('name')
                    ->helperText(fn (Get $get): string => self::isService($get)
                        ? __('Describes what the account does, e.g. catalog_importer.')
                        : __('Generated automatically. The owner can draw another one on the invitation page.'))
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z0-9]+(_[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true)
                    ->visible(fn (Get $get, string $operation): bool => $operation === 'edit' || self::isService($get)),
                TextInput::make('owner_name')
                    ->label(__('Owner'))
                    ->helperText(__('Person responsible for this client, for traceability.'))
                    ->required()
                    ->maxLength(255),
                // Not unique: one owner can have several cabinets (D38).
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(ClientType::class)
                    ->default(ClientType::Maui)
                    ->required()
                    ->live()
                    // Abilities depend on the type: it cannot change once created.
                    ->disabledOn('edit'),
                Textarea::make('notes')
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    private static function isService(Get $get): bool
    {
        $type = $get('type');

        return ($type instanceof ClientType ? $type : ClientType::tryFrom((string) $type)) === ClientType::Service;
    }
}
