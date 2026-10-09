<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Tables\MembersTable;
use App\Models\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Members of the hiscores front: Discord accounts let in by an invitation
 * (docs/DECISIONS.md D64). Read only apart from disabling and enabling.
 */
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Front members';

    protected static ?string $modelLabel = 'front member';

    protected static ?string $recordTitleAttribute = 'username';

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
        return MembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
        ];
    }
}
