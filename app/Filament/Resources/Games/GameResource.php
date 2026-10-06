<?php

namespace App\Filament\Resources\Games;

use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Games\Pages\ViewGame;
use App\Filament\Resources\Games\Schemas\GameInfolist;
use App\Filament\Resources\Games\Tables\GamesTable;
use App\Models\Game;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Game catalog, read only: games come from the catalog push of
 * maui-repository, or from a score for an unknown romname (docs/DECISIONS.md D47).
 */
class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $recordTitleAttribute = 'description';

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
        return GameInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GamesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGames::route('/'),
            'view' => ViewGame::route('/{record}'),
        ];
    }
}
