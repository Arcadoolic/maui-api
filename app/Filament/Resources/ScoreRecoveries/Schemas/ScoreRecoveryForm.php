<?php

namespace App\Filament\Resources\ScoreRecoveries\Schemas;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Diagnostic;
use App\Models\Player;
use App\Models\Score;
use App\Services\Scores\ScoreRecoveries;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

/**
 * The request to recover a score (docs/DECISIONS.md D93), from the list of
 * requests or from a diagnostic, whose cabinet and game it then takes.
 */
class ScoreRecoveryForm
{
    /**
     * @return list<mixed>
     */
    public static function fields(?Diagnostic $diagnostic = null): array
    {
        $clientId = fn (Get $get): ?int => $diagnostic !== null ? $diagnostic->client_id : (filled($get('client_id')) ? (int) $get('client_id') : null);

        return array_values(array_filter([
            $diagnostic === null
                ? Select::make('client_id')
                    ->label(__('Cabinet'))
                    ->options(fn (): array => Client::query()->where('type', ClientType::Maui)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                : null,
            Select::make('player_id')
                ->label(__('Player'))
                // Only the cabinet's players: the cabinet sends the score of one of them.
                ->options(fn (Get $get): array => ($id = $clientId($get)) === null ? [] : Client::query()->find($id)?->players()
                    ->orderBy('pseudo_3')->pluck('players.pseudo_3', 'players.id')->all() ?? [])
                ->searchable()
                ->required(),
            $diagnostic === null
                ? TextInput::make('romname')
                    ->label(__('Game'))
                    ->required()
                    ->regex('/^[a-z0-9_]{1,32}$/')
                    ->helperText(__('MAME short name, e.g. atetris.'))
                : null,
            TextInput::make('table')
                ->default(Score::DEFAULT_TABLE)
                ->required()
                ->regex('/^[a-z0-9_]{1,32}$/')
                ->helperText(__('mhiex table: default, unless the game keeps several.')),
            TextInput::make('score')
                ->integer()
                ->minValue(1)
                ->maxValue(9007199254740991)
                ->required()
                ->helperText(__('Exactly as in the file: the cabinet looks for this very value.')),
            DateTimePicker::make('achieved_at')
                ->label(__('Played on'))
                ->seconds(false)
                ->maxDate(now())
                ->required()
                ->helperText(__('The score\'s date. From the cabinet\'s log, else the screenshot, else the file.')),
            Textarea::make('reason')
                ->required()
                ->maxLength(1000)
                ->helperText(__('What was checked, e.g. "screenshot and .hi agree, extractor fixed in mhiex 2.2.7".')),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function open(array $data, ?Diagnostic $diagnostic = null): void
    {
        $clientId = $diagnostic !== null ? $diagnostic->client_id : (int) $data['client_id'];
        $player = Player::query()->findOrFail((int) $data['player_id']);
        app(ScoreRecoveries::class)->open(
            $player,
            $clientId,
            $diagnostic !== null ? $diagnostic->romname : (string) $data['romname'],
            (string) $data['table'],
            (int) $data['score'],
            Carbon::parse((string) $data['achieved_at']),
            (string) $data['reason'],
            $diagnostic,
        );
    }
}
