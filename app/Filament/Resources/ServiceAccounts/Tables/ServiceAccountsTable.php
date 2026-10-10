<?php

namespace App\Filament\Resources\ServiceAccounts\Tables;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A service account sends no heartbeat: its token is its only "last seen" (D43).
            ->modifyQueryUsing(fn (Builder $query) => $query->withMax('tokens as token_last_used_at', 'last_used_at'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('owner_name')->label(__('Owner'))->searchable()->sortable(),
                TextColumn::make('token_last_used_at')->label(__('Token last used'))->since()->sortable()->placeholder(__('Never used')),
                TextColumn::make('email')->searchable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(collect(ClientType::cases())
                    ->filter(fn (ClientType $type): bool => $type->isService())
                    ->mapWithKeys(fn (ClientType $type): array => [$type->value => $type->getLabel()])
                    ->all()),
                SelectFilter::make('status')->options(ClientStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
