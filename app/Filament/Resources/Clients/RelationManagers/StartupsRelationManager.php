<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Versions reported by the cabinet at each ONLINE startup. Read-only.
 */
class StartupsRelationManager extends RelationManager
{
    protected static string $relationship = 'startups';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label(__('Received'))->dateTime()->sortable(),
                TextColumn::make('maui_version')->label(__('MAUI')),
                TextColumn::make('mame_version')->label(__('MAME')),
                TextColumn::make('os')->label(__('OS')),
                TextColumn::make('os_name')->label(__('OS name'))->placeholder('-'),
                TextColumn::make('os_version')->label(__('OS version')),
                TextColumn::make('client_datetime')->label(__('Cabinet clock'))->dateTime(),
            ]);
    }
}
