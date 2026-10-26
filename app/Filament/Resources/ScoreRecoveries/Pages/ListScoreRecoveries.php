<?php

namespace App\Filament\Resources\ScoreRecoveries\Pages;

use App\Filament\Resources\ScoreRecoveries\Schemas\ScoreRecoveryForm;
use App\Filament\Resources\ScoreRecoveries\ScoreRecoveryResource;
use App\Models\ScoreRecovery;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListScoreRecoveries extends ListRecords
{
    protected static string $resource = ScoreRecoveryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->requestAction(),
        ];
    }

    public function requestAction(): Action
    {
        return Action::make('request')
            ->label(__('Recover a score'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->modalDescription(__('The cabinet looks for this score in its own file, with its current extractor, and sends it if it is there. Open for :days days.', ['days' => ScoreRecovery::KEEP_OPEN_DAYS]))
            ->schema(ScoreRecoveryForm::fields())
            ->action(fn (array $data) => ScoreRecoveryForm::open($data));
    }
}
