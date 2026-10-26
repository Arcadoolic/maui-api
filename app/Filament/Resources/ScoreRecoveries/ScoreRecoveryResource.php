<?php

namespace App\Filament\Resources\ScoreRecoveries;

use App\Filament\Resources\ScoreRecoveries\Pages\ListScoreRecoveries;
use App\Filament\Resources\ScoreRecoveries\Tables\ScoreRecoveriesTable;
use App\Models\ScoreRecovery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Requests to recover a score a cabinet's file holds but MAUI-API never got:
 * opened by an administrator, answered by the cabinet (docs/DECISIONS.md D93).
 */
class ScoreRecoveryResource extends Resource
{
    protected static ?string $model = ScoreRecovery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

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
        return ScoreRecoveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScoreRecoveries::route('/'),
        ];
    }
}
