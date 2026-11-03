<?php

namespace App\Filament\Resources\Games\Schemas;

use App\Models\Game;
use App\Models\GameMedia;
use App\Models\GameOpinion;
use App\Models\PlayerOpinion;
use App\Models\Score;
use App\Services\Leaderboards\Leaderboards;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Storage;

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
                // What every place shows of the game: the first of each (D99).
                Section::make(__('Pictures'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('detail.screenscraper_id')->label(__('ScreenScraper sheet'))->fontFamily(FontFamily::Mono)->placeholder('-'),
                        TextEntry::make('detail.media_donor_id')->label(__('Media donor'))->fontFamily(FontFamily::Mono)->placeholder(__('None')),
                        TextEntry::make('detail.media_region')->label(__('Priority region'))->fontFamily(FontFamily::Mono)->placeholder(__('None: eu, wor, us, jp, ss')),
                        ...array_map(fn (string $type): ImageEntry => self::picture($type), ['marquee', 'flyer', 'logo']),
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
                // One row per cabinet that voted or played (docs/DECISIONS.md D75).
                Section::make(__('Cabinets'))
                    ->schema([
                        RepeatableEntry::make('opinions')
                            ->hiddenLabel()
                            ->state(fn (Game $record): array => $record->opinions()->with('client')->get()
                                ->sortBy(fn (GameOpinion $opinion): string => $opinion->client->name)
                                ->map(fn (GameOpinion $opinion): array => [
                                    'cabinet' => $opinion->client->name,
                                    'vote' => match ($opinion->vote) {
                                        GameOpinion::VOTE_UP => __('Thumbs up'),
                                        GameOpinion::VOTE_DOWN => __('Thumbs down'),
                                        default => __('Neutral'),
                                    },
                                    'play_count' => $opinion->play_count,
                                    'last_played_at' => $opinion->last_played_at,
                                ])->values()->all())
                            ->placeholder(__('No cabinet reported this game yet.'))
                            ->table([
                                TableColumn::make(__('Cabinet')),
                                TableColumn::make(__('Vote')),
                                TableColumn::make(__('Plays')),
                                TableColumn::make(__('Last played')),
                            ])
                            ->schema([
                                TextEntry::make('cabinet'),
                                TextEntry::make('vote'),
                                TextEntry::make('play_count')->numeric(),
                                TextEntry::make('last_played_at')->dateTime()->placeholder('-'),
                            ]),
                    ]),
                // One row per player that voted on the front (docs/DECISIONS.md D86).
                Section::make(__('Players\' votes'))
                    ->schema([
                        RepeatableEntry::make('player_opinions')
                            ->hiddenLabel()
                            ->state(fn (Game $record): array => $record->playerOpinions()->with('player')->get()
                                ->sortBy(fn (PlayerOpinion $opinion): string => $opinion->player->pseudo_3)
                                ->map(fn (PlayerOpinion $opinion): array => [
                                    'player' => $opinion->player->pseudo_3,
                                    'vote' => $opinion->vote === GameOpinion::VOTE_UP ? __('Thumbs up') : __('Thumbs down'),
                                    'voted_at' => $opinion->voted_at,
                                ])->values()->all())
                            ->placeholder(__('No player voted on this game yet.'))
                            ->table([
                                TableColumn::make(__('Player')),
                                TableColumn::make(__('Vote')),
                                TableColumn::make(__('Voted')),
                            ])
                            ->schema([
                                TextEntry::make('player')->fontFamily(FontFamily::Mono),
                                TextEntry::make('vote'),
                                TextEntry::make('voted_at')->dateTime(),
                            ]),
                    ]),
            ]);
    }

    /** Kept on a private disk: shown from its bytes, with where it comes from. */
    private static function picture(string $type): ImageEntry
    {
        $media = fn (Game $record): ?GameMedia => $record->media()->where('type', $type)->where('position', 0)->first();

        return ImageEntry::make("picture_{$type}")
            ->label(ucfirst($type))
            ->state(fn (Game $record): ?string => ($picture = $media($record)) !== null && Storage::disk(GameMedia::DISK)->exists($picture->path)
                ? 'data:'.$picture->mime.';base64,'.base64_encode((string) Storage::disk(GameMedia::DISK)->get($picture->path))
                : null)
            ->imageHeight(160)
            ->helperText(function (Game $record) use ($media): ?string {
                $donor = $media($record)?->donor;
                if ($donor === null) {
                    return null;
                }

                return $donor ? __('From the media donor') : __('From the game\'s own sheet');
            })
            ->placeholder(__('None'));
    }
}
