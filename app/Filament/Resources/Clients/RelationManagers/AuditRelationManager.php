<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Audit trail of the client: who did what, and when. Read-only.
 */
class AuditRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $title = 'Audit log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('causer'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime(),
                TextColumn::make('event')->badge(),
                TextColumn::make('causer.name')->label(__('By'))->placeholder(__('Cabinet owner or system')),
                TextColumn::make('attribute_changes.attributes')
                    ->label(__('Changes'))
                    ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_UNESCAPED_SLASHES) : (string) $state)
                    ->placeholder('-')
                    ->wrap(),
            ]);
    }
}
