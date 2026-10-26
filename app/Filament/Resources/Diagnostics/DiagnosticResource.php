<?php

namespace App\Filament\Resources\Diagnostics;

use App\Filament\Resources\Diagnostics\Pages\ListDiagnostics;
use App\Filament\Resources\Diagnostics\Tables\DiagnosticsTable;
use App\Models\Diagnostic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The files of a game sent by a cabinet when a score is missing: read,
 * downloaded as a zip for mhiex, deleted (docs/DECISIONS.md D92).
 */
class DiagnosticResource extends Resource
{
    protected static ?string $model = Diagnostic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return DiagnosticsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiagnostics::route('/'),
        ];
    }
}
