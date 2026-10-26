<?php

namespace App\Filament\Resources\ScoreRecoveries\Tables;

use App\Enums\ScoreRecoveryStatus;
use App\Models\ScoreRecovery;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ScoreRecoveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'player']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Requested'))->since()->sortable(),
                TextColumn::make('client.name')->label(__('Cabinet'))->searchable(),
                TextColumn::make('player.pseudo_3')->label(__('Player'))->searchable()->fontFamily('mono'),
                TextColumn::make('romname')->label(__('Game'))->searchable()->fontFamily('mono'),
                TextColumn::make('score')->numeric(),
                TextColumn::make('achieved_at')->label(__('Played on'))->dateTime()->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('closed_at')->label(__('Answered'))->since()->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ScoreRecoveryStatus::class),
                SelectFilter::make('client')->label(__('Cabinet'))->relationship('client', 'name')->searchable(),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->color('gray')
                    ->modalHeading(fn (ScoreRecovery $record): string => __('Recovery of :score on :rom', ['score' => number_format($record->score), 'rom' => $record->romname]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (ScoreRecovery $record): HtmlString => self::details($record)),
            ]);
    }

    /** Why it was asked, and what the cabinet answered: the table it read when it did not find it. */
    private static function details(ScoreRecovery $record): HtmlString
    {
        $result = $record->result ?? [];
        $rows = is_array($result['table'] ?? null) ? $result['table'] : [];
        $table = $rows === [] ? '' : '<table style="margin-top: 0.5rem; font-size: 0.8rem">'.implode('', array_map(
            fn (array $row): string => '<tr><td style="padding-right: 1rem">'.e((string) ($row['rank'] ?? '')).'</td>'
                .'<td style="padding-right: 1rem">'.e((string) ($row['score'] ?? '')).'</td>'
                .'<td style="font-family: monospace">'.e((string) ($row['name'] ?? '')).'</td></tr>',
            $rows,
        )).'</table>';
        $other = array_diff_key($result, ['table' => true]);

        return new HtmlString(
            '<p><strong>'.e($record->status->getLabel()).'</strong>'
            .($record->closed_at ? ' · '.e($record->closed_at->toDateTimeString()) : '').'</p>'
            .'<p style="margin-top: 0.5rem">'.e($record->reason).'</p>'
            .($other === [] ? '' : '<pre style="margin-top: 0.5rem; font-size: 0.75rem">'.e((string) json_encode($other, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>')
            .($table === '' ? '' : '<p style="margin-top: 0.5rem">'.e(__('Table read by the cabinet:')).'</p>'.$table)
        );
    }
}
