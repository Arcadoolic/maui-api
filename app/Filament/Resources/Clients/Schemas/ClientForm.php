<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->helperText(__('Generated automatically. The owner can draw another one on the invitation page.'))
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z0-9]+(_[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true)
                    ->hiddenOn('create'),
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
                    // Abilities depend on the type: it cannot change once created.
                    ->disabledOn('edit'),
                Textarea::make('notes')
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }
}
