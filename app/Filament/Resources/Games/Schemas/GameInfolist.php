<?php

namespace App\Filament\Resources\Games\Schemas;

use App\Models\Game;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class GameInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Game'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('romname')->fontFamily(FontFamily::Mono)->copyable(),
                        TextEntry::make('description'),
                        TextEntry::make('manufacturer')->placeholder('-'),
                        TextEntry::make('year')->placeholder('-'),
                        TextEntry::make('parent_romname')->label(__('Parent'))->fontFamily(FontFamily::Mono)->placeholder('-'),
                        TextEntry::make('players')
                            ->state(fn (Game $record): string => __(':sim simultaneous, :alt alternating', [
                                'sim' => $record->player_sim ?? '?',
                                'alt' => $record->player_alt ?? '?',
                            ])),
                        TextEntry::make('genreCategory.name')->label(__('Genre'))->placeholder('-'),
                        TextEntry::make('catver')
                            ->label(__('Catver'))
                            ->state(fn (Game $record): ?string => $record->catverCategory?->fullName())
                            ->placeholder('-'),
                        IconEntry::make('mature')->boolean(),
                        TextEntry::make('catalogued_at')->dateTime()->placeholder(__('Known from a score only')),
                    ]),
            ]);
    }
}
