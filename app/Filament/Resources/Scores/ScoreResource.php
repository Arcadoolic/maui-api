<?php

namespace App\Filament\Resources\Scores;

use App\Filament\Resources\Scores\Pages\ListScores;
use App\Filament\Resources\Scores\Tables\ScoresTable;
use App\Models\Score;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Scores sent by the cabinets, read only but for moderation: hide a score,
 * show it again (docs/DECISIONS.md D50).
 */
class ScoreResource extends Resource
{
    protected static ?string $model = Score::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

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

    public static function table(Table $table): Table
    {
        return ScoresTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScores::route('/'),
        ];
    }
}
