<?php

namespace App\Filament\Resources\ServiceAccounts\RelationManagers;

use App\Enums\ClientType;
use App\Models\Client;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Players a TV app shows (docs/DECISIONS.md D102). Read-only: they are
 * linked with their PIN, and unlinked, from the TV.
 */
class TvPlayersRelationManager extends RelationManager
{
    protected static string $relationship = 'tvPlayers';

    protected static ?string $title = 'Players';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Client && $ownerRecord->type === ClientType::Tv;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('tv_players.linked_at', 'desc')
            ->columns([
                TextColumn::make('pseudo_3')->label(__('Initials'))->fontFamily('mono'),
                IconColumn::make('is_public')->label(__('Public'))->boolean(),
                TextColumn::make('linked_at')->label(__('Linked'))->dateTime(),
            ]);
    }
}
