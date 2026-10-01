<?php

namespace App\Filament\Resources\Players\Tables;

use App\Enums\PlayerStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PlayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('clients'))
            ->defaultSort('pseudo_3')
            ->columns([
                TextColumn::make('pseudo_3')->label(__('Initials'))->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('status')->badge(),
                IconColumn::make('locked')
                    ->label(__('PIN locked'))
                    ->state(fn ($record): bool => $record->isLocked())
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
                IconColumn::make('is_public')->label(__('Public'))->boolean(),
                TextColumn::make('clients_count')->label(__('Cabinets'))->sortable(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PlayerStatus::class),
                TernaryFilter::make('is_public')->label(__('Public')),
                TernaryFilter::make('locked')
                    ->label(__('PIN locked'))
                    ->nullable()
                    ->attribute('pin_locked_at'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
