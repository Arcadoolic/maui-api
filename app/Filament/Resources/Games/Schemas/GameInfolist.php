<?php

namespace App\Filament\Resources\Games\Schemas;

use App\Models\Game;
use App\Models\Score;
use App\Services\Leaderboards\Leaderboards;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
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
                // Same top 9 as GET /leaderboards/{romname} (docs/DECISIONS.md D52).
                Section::make(__('Leaderboard'))
                    ->schema([
                        RepeatableEntry::make('leaderboard')
                            ->hiddenLabel()
                            ->state(fn (Game $record): array => app(Leaderboards::class)->top($record)->values()
                                ->map(fn (Score $score, int $index): array => [
                                    'rank' => $index + 1,
                                    'player' => $score->player->pseudo_3,
                                    'score' => $score->score,
                                    'cabinet' => $score->client->name,
                                    'achieved_at' => $score->achieved_at,
                                ])->all())
                            ->placeholder(__('No visible score yet.'))
                            ->table([
                                TableColumn::make(__('Rank')),
                                TableColumn::make(__('Player')),
                                TableColumn::make(__('Score')),
                                TableColumn::make(__('Cabinet')),
                                TableColumn::make(__('Achieved')),
                            ])
                            ->schema([
                                TextEntry::make('rank'),
                                TextEntry::make('player')->fontFamily(FontFamily::Mono),
                                TextEntry::make('score')->numeric(),
                                TextEntry::make('cabinet'),
                                TextEntry::make('achieved_at')->dateTime(),
                            ]),
                    ]),
            ]);
    }
}
