<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** A cabinet: its type is set by the page that creates it (CreateClient). */
class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // A cabinet gets a generated arcade name, which its owner can draw
                // again on the invitation page (D39): only an existing one is renamed here.
                TextInput::make('name')
                    ->helperText(__('Generated automatically. The owner can draw another one on the invitation page.'))
                    ->required()
                    ->maxLength(64)
                    ->regex('/^[a-z0-9]+(_[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true)
                    ->visibleOn('edit'),
                ...self::ownerFields(__('Person responsible for this cabinet, for traceability.')),
            ]);
    }

    /**
     * Who answers for a client, and the admin's notes: the same for a cabinet
     * and for a service account.
     *
     * @return list<TextInput|Textarea>
     */
    public static function ownerFields(string $ownerHelp): array
    {
        return [
            TextInput::make('owner_name')
                ->label(__('Owner'))
                ->helperText($ownerHelp)
                ->required()
                ->maxLength(255),
            // Not unique: one owner can have several cabinets (D38).
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255),
            Textarea::make('notes')
                ->maxLength(2000)
                ->columnSpanFull(),
        ];
    }
}
