<?php

namespace App\Filament\Resources\Diagnostics\Tables;

use App\Models\Diagnostic;
use App\Services\Diagnostics\DiagnosticArchive;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DiagnosticsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('client'))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->since()->sortable()
                    ->tooltip(fn (Diagnostic $record): string => $record->received_at->toDateTimeString()),
                TextColumn::make('romname')->label(__('Game'))->searchable()->fontFamily('mono'),
                TextColumn::make('client.name')->label(__('Cabinet'))->searchable(),
                TextColumn::make('comment')->placeholder('-')->limit(60)->wrap(),
                TextColumn::make('files')->label(__('Files'))
                    ->state(fn (Diagnostic $record): string => count($record->files).' · '.Number::fileSize($record->size)),
                IconColumn::make('has_logs')->label(__('Logs'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('client')->label(__('Cabinet'))->relationship('client', 'name')->searchable(),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->color('gray')
                    ->modalHeading(fn (Diagnostic $record): string => __('Diagnostic of :rom', ['rom' => $record->romname]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (Diagnostic $record): HtmlString => self::details($record)),
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Diagnostic $record): BinaryFileResponse => self::download($record)),
                DeleteAction::make(),
            ]);
    }

    /** The zip, laid out for mhiex (DiagnosticArchive), recorded in the audit log. */
    private static function download(Diagnostic $record): BinaryFileResponse
    {
        $archive = app(DiagnosticArchive::class);
        activity(DiagnosticArchive::LOG_NAME)
            ->performedOn($record)
            ->event('diagnostic.downloaded')
            ->log('diagnostic.downloaded');

        return response()->download($archive->build($record), $archive->fileName($record), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }

    /** The comment, the files and what the cabinet said about the game. */
    private static function details(Diagnostic $record): HtmlString
    {
        $rows = implode('', array_map(fn (array $file): string => '<tr>'
            .'<td style="padding-right: 1rem; font-family: monospace">'.e($file['area'].'/'.$file['name']).'</td>'
            .'<td style="padding-right: 1rem">'.e(Number::fileSize($file['size'])).'</td>'
            .'<td style="padding-right: 1rem">'.e($file['modified'] ?? '-').'</td>'
            .'<td style="font-family: monospace; font-size: 0.7rem">'.e(substr($file['sha256'], 0, 16)).'…</td>'
            .'</tr>', $record->files));
        $report = json_encode($record->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new HtmlString(
            '<p><strong>'.e($record->client->name).'</strong> · '.e($record->received_at->toDateTimeString()).'</p>'
            .($record->comment === null ? '' : '<p style="margin-top: 0.5rem">'.e($record->comment).'</p>')
            .($rows === '' ? '<p style="margin-top: 1rem">'.e(__('No file.')).'</p>' : '<table style="margin-top: 1rem; font-size: 0.8rem">'.$rows.'</table>')
            .'<pre style="margin-top: 1rem; font-size: 0.75rem; overflow-x: auto">'.e((string) $report).'</pre>'
        );
    }
}
