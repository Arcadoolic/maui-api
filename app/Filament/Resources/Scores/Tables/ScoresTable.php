<?php

namespace App\Filament\Resources\Scores\Tables;

use App\Enums\ScoreAttribution;
use App\Models\Score;
use App\Services\Scores\ScoreModeration;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['game', 'player', 'client']))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->since()->sortable(),
                TextColumn::make('game.romname')->label(__('Game'))->searchable()->fontFamily('mono')
                    ->description(fn (Score $record): string => $record->game->description),
                TextColumn::make('player.pseudo_3')->label(__('Player'))->searchable()->fontFamily('mono'),
                TextColumn::make('score')->numeric()->sortable(),
                TextColumn::make('table')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rank_on_cabinet')->label(__('Rank'))->placeholder('-')->toggleable(),
                TextColumn::make('client.name')->label(__('Cabinet'))->searchable(),
                // Declared on the cabinet, for a game that writes no name (D61).
                TextColumn::make('attribution')->label(__('Player from'))->badge()
                    ->color(fn (ScoreAttribution $state): string => $state === ScoreAttribution::Declared ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('achieved_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('hidden')
                    ->label(__('Hidden'))
                    ->state(fn (Score $record): bool => $record->isHidden())
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
            ])
            ->filters([
                SelectFilter::make('game')->relationship('game', 'romname')->searchable(),
                SelectFilter::make('player')->relationship('player', 'pseudo_3')->searchable(),
                SelectFilter::make('client')->label(__('Cabinet'))->relationship('client', 'name')->searchable(),
                SelectFilter::make('attribution')->label(__('Player from'))->options(ScoreAttribution::class),
                TernaryFilter::make('hidden')
                    ->label(__('Hidden'))
                    ->nullable()
                    ->attribute('hidden_at'),
            ])
            ->recordActions([
                Action::make('hide')
                    ->label(__('Hide'))
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Removes the score from the leaderboards and from the player\'s best.'))
                    ->visible(fn (Score $record): bool => ! $record->isHidden())
                    ->action(fn (Score $record) => app(ScoreModeration::class)->hide($record)),
                Action::make('show')
                    ->label(__('Show'))
                    ->icon(Heroicon::OutlinedEye)
                    ->requiresConfirmation()
                    ->visible(fn (Score $record): bool => $record->isHidden())
                    ->action(fn (Score $record) => app(ScoreModeration::class)->unhide($record)),
            ]);
    }
}
