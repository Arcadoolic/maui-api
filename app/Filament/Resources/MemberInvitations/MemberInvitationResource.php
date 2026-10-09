<?php

namespace App\Filament\Resources\MemberInvitations;

use App\Filament\Resources\MemberInvitations\Pages\ListMemberInvitations;
use App\Filament\Resources\MemberInvitations\Tables\MemberInvitationsTable;
use App\Models\MemberInvitation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Invitation links to the hiscores front (docs/DECISIONS.md D65). Created
 * from the list, whose link is shown once; never edited, revoked instead.
 */
class MemberInvitationResource extends Resource
{
    protected static ?string $model = MemberInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Front invitations';

    protected static ?string $modelLabel = 'front invitation';

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
        return MemberInvitationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberInvitations::route('/'),
        ];
    }
}
