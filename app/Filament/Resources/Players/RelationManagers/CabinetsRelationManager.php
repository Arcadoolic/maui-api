<?php

namespace App\Filament\Resources\Players\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cabinets the player is linked to. Read-only: links are made and removed
 * from the cabinets.
 */
class CabinetsRelationManager extends RelationManager
{
    protected static string $relationship = 'clients';

    protected static ?string $title = 'Cabinets';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('client_player.linked_at', 'desc')
            ->columns([
                TextColumn::make('name')->fontFamily('mono'),
                TextColumn::make('owner_name')->label(__('Owner')),
                TextColumn::make('linked_at')->label(__('Linked'))->dateTime(),
            ]);
    }
}
