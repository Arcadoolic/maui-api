<?php

namespace App\Filament\Resources\Games\Tables;

use App\Enums\CategorySource;
use App\Models\Category;
use App\Models\Game;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['genreCategory', 'catverCategory.parent']))
            ->defaultSort('romname')
            ->columns([
                TextColumn::make('romname')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('description')->searchable()->sortable()->wrap(),
                TextColumn::make('manufacturer')->searchable()->sortable()->toggleable(),
                TextColumn::make('year')->sortable(),
                TextColumn::make('parent_romname')->label(__('Parent'))->fontFamily('mono')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('player_sim')->label(__('Sim.'))->sortable(),
                TextColumn::make('player_alt')->label(__('Alt.'))->sortable(),
                TextColumn::make('genreCategory.name')->label(__('Genre'))->placeholder('-'),
                TextColumn::make('catver')
                    ->label(__('Catver'))
                    ->state(fn (Game $record): ?string => $record->catverCategory?->fullName())
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('catalogued_at')->since()->sortable()->placeholder(__('Score only'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('genre_category')
                    ->label(__('Genre'))
                    ->relationship('genreCategory', 'name', fn (Builder $query) => $query->where('source', CategorySource::Genre))
                    ->searchable()
                    ->preload(),
                // A catver genre also selects the games filed under its subgenres.
                SelectFilter::make('catver_category')
                    ->label(__('Catver'))
                    ->options(fn (): array => self::catverOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->where(fn (Builder $query) => $query
                            ->where('catver_category_id', $data['value'])
                            ->orWhereIn('catver_category_id', Category::query()->select('id')->where('parent_id', $data['value'])))),
                SelectFilter::make('player_sim')
                    ->label(__('Simultaneous players'))
                    ->options(fn (): array => self::playerOptions('player_sim')),
                SelectFilter::make('player_alt')
                    ->label(__('Alternating players'))
                    ->options(fn (): array => self::playerOptions('player_alt')),
                TernaryFilter::make('catalogued')
                    ->label(__('Catalogued'))
                    ->nullable()
                    ->attribute('catalogued_at'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Catver genres and subgenres, "Genre / Subgenre" sorted.
     *
     * @return array<int, string>
     */
    private static function catverOptions(): array
    {
        return Category::query()
            ->with('parent')
            ->where('source', CategorySource::Catver)
            ->get()
            ->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullName()])
            ->sort()
            ->all();
    }

    /**
     * Player counts present in the catalog.
     *
     * @return array<int, string>
     */
    private static function playerOptions(string $column): array
    {
        return Game::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->mapWithKeys(fn (mixed $count): array => [(int) $count => (string) $count])
            ->all();
    }
}
