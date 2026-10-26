<?php

namespace App\Filament\Resources\Scores\Tables;

use App\Enums\ScoreAttribution;
use App\Enums\ScoreIntegrity;
use App\Models\Score;
use App\Services\Scores\IntegrityVerdict;
use App\Services\Scores\ScoreModeration;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

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
                    // Recovered on an administrator's request after the fact (D93).
                    ->color(fn (ScoreAttribution $state): string => match ($state) {
                        ScoreAttribution::Declared => 'warning',
                        ScoreAttribution::Recovered => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                // The cabinet's report on the game (D87): unknown for the cabinets sending none.
                TextColumn::make('integrity_verdict')->label(__('Integrity'))->badge()
                    ->placeholder(__('Unknown'))
                    ->tooltip(fn (Score $record): ?string => $record->integrity_reasons
                        ? implode(' · ', array_map(IntegrityVerdict::label(...), $record->integrity_reasons))
                        : null),
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
                SelectFilter::make('integrity_verdict')->label(__('Integrity'))->options(ScoreIntegrity::class),
                TernaryFilter::make('hidden')
                    ->label(__('Hidden'))
                    ->nullable()
                    ->attribute('hidden_at'),
            ])
            ->recordActions([
                Action::make('integrity')
                    ->label(__('Integrity'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->visible(fn (Score $record): bool => $record->integrity !== null)
                    ->modalHeading(__('Report of the game'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (Score $record): HtmlString => self::integrityReport($record)),
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

    /** What the cabinet saw of the game, and what the verdict made of it (D87). */
    private static function integrityReport(Score $record): HtmlString
    {
        $reasons = $record->integrity_reasons ?? [];
        $list = $reasons === []
            ? '<p>'.e(__('Nothing that changes how the game was played.')).'</p>'
            : '<ul style="list-style: disc; padding-left: 1.25rem">'.implode('', array_map(
                fn (string $reason): string => '<li>'.e(IntegrityVerdict::label($reason)).'</li>',
                $reasons,
            )).'</ul>';
        $json = json_encode($record->integrity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new HtmlString(
            '<p><strong>'.e($record->integrity_verdict?->getLabel() ?? __('Unknown')).'</strong></p>'.$list
            .'<pre style="margin-top: 1rem; font-size: 0.75rem; overflow-x: auto">'.e((string) $json).'</pre>'
        );
    }
}
