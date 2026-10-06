<?php

namespace App\Filament\Resources\Players;

use App\Filament\Resources\Clients\RelationManagers\AuditRelationManager;
use App\Filament\Resources\Players\Pages\ListPlayers;
use App\Filament\Resources\Players\Pages\ViewPlayer;
use App\Filament\Resources\Players\RelationManagers\CabinetsRelationManager;
use App\Filament\Resources\Players\Schemas\PlayerInfolist;
use App\Filament\Resources\Players\Tables\PlayersTable;
use App\Models\Player;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Players, created by the cabinets. Read only apart from moderation:
 * disable, enable, unlock (docs/DECISIONS.md D48). No delete: scores will
 * reference them.
 */
class PlayerResource extends Resource
{
    protected static ?string $model = Player::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'pseudo_3';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return PlayerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlayersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CabinetsRelationManager::class,
            AuditRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlayers::route('/'),
            'view' => ViewPlayer::route('/{record}'),
        ];
    }
}
