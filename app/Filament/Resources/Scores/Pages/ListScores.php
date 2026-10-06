<?php

namespace App\Filament\Resources\Scores\Pages;

use App\Filament\Resources\Scores\ScoreResource;
use Filament\Resources\Pages\ListRecords;

class ListScores extends ListRecords
{
    protected static string $resource = ScoreResource::class;
}
