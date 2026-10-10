<?php

namespace App\Filament\Resources\ServiceAccounts;

use App\Enums\ClientType;
use App\Filament\Resources\Clients\RelationManagers\AuditRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientInfolist;
use App\Filament\Resources\ServiceAccounts\Pages\CreateServiceAccount;
use App\Filament\Resources\ServiceAccounts\Pages\EditServiceAccount;
use App\Filament\Resources\ServiceAccounts\Pages\ListServiceAccounts;
use App\Filament\Resources\ServiceAccounts\Pages\ViewServiceAccount;
use App\Filament\Resources\ServiceAccounts\Schemas\ServiceAccountForm;
use App\Filament\Resources\ServiceAccounts\Tables\ServiceAccountsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The technical accounts: the clients that are not cabinets, service accounts
 * and bots (ClientType::isService()). Apart from the cabinets since D79: they
 * have no machine, no heartbeat and no invitation, only a token.
 */
class ServiceAccountResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'service account';

    protected static ?string $pluralModelLabel = 'service accounts';

    protected static ?string $navigationLabel = 'Service accounts';

    protected static ?string $slug = 'service-accounts';

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', '!=', ClientType::Maui);
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceAccountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AuditRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceAccounts::route('/'),
            'create' => CreateServiceAccount::route('/create'),
            'view' => ViewServiceAccount::route('/{record}'),
            'edit' => EditServiceAccount::route('/{record}/edit'),
        ];
    }
}
