<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Scores\ScoreResource;
use App\Models\Score;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The last scores received, hidden ones included, on the dashboard
 * (docs/DECISIONS.md D52): moderation starts here.
 */
class LatestScores extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Latest scores'))
            ->query(fn () => Score::query()->with(['game', 'player', 'client'])->latest('received_at')->limit(10))
            ->paginated(false)
            ->columns([
                TextColumn::make('received_at')->since(),
                TextColumn::make('game.romname')->label(__('Game'))->fontFamily('mono')
                    ->description(fn (Score $record): string => $record->game->description),
                TextColumn::make('player.pseudo_3')->label(__('Player'))->fontFamily('mono'),
                TextColumn::make('score')->numeric(),
                TextColumn::make('client.name')->label(__('Cabinet')),
                IconColumn::make('hidden')
                    ->label(__('Hidden'))
                    ->state(fn (Score $record): bool => $record->isHidden())
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
            ])
            ->recordUrl(fn (): string => ScoreResource::getUrl());
    }
}
