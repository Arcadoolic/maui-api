<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestStartup'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge(),
                IconColumn::make('online')
                    ->state(fn (Client $record): bool => $record->isOnline())
                    ->boolean(),
                TextColumn::make('last_heartbeat_at')->label(__('Last seen'))->since()->sortable()->placeholder(__('Never')),
                TextColumn::make('latestStartup.maui_version')->label(__('MAUI')),
                TextColumn::make('latestStartup.mame_version')->label(__('MAME')),
                TextColumn::make('latestStartup.os')->label(__('OS')),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(ClientType::class),
                SelectFilter::make('status')->options(ClientStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
