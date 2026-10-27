<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\ClientStatus;
use App\Enums\ClientType;
use App\Models\Client;
use App\Models\ClientStartup;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestStartup'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('owner_name')->label(__('Owner'))->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                IconColumn::make('online')
                    ->state(fn (Client $record): bool => $record->isOnline())
                    ->boolean(),
                TextColumn::make('last_heartbeat_at')->label(__('Last seen'))->since()->sortable()->placeholder(__('Never')),
                TextColumn::make('latestStartup.maui_version')->label(__('MAUI')),
                TextColumn::make('latestStartup.mame_version')->label(__('MAME')),
                TextColumn::make('latestStartup.os')->label(__('OS')),
                TextColumn::make('email')->searchable()->toggleable(),
            ])
            ->filters([
                // The list opens on the cabinets online and active.
                SelectFilter::make('status')->options(ClientStatus::class)->default(ClientStatus::Active->value),
                // Same threshold as the Online column (Client::isOnline()).
                TernaryFilter::make('online')
                    ->label(__('Online'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('last_heartbeat_at', '>', self::onlineSince()),
                        false: fn (Builder $query) => $query->where(fn (Builder $query) => $query
                            ->whereNull('last_heartbeat_at')
                            ->orWhere('last_heartbeat_at', '<=', self::onlineSince())),
                    ),
                SelectFilter::make('last_seen')
                    ->label(__('Last seen'))
                    ->options([
                        'day' => __('In the last 24 hours'),
                        'week' => __('In the last 7 days'),
                        'month_ago' => __('Not for 30 days'),
                        'never' => __('Never'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'day' => $query->where('last_heartbeat_at', '>=', now()->subDay()),
                        'week' => $query->where('last_heartbeat_at', '>=', now()->subWeek()),
                        'month_ago' => $query->where('last_heartbeat_at', '<', now()->subDays(30)),
                        'never' => $query->whereNull('last_heartbeat_at'),
                        default => $query,
                    }),
                self::latestStartupFilter('maui_version', __('MAUI')),
                self::latestStartupFilter('mame_version', __('MAME')),
                self::latestStartupFilter('os', __('OS'), versions: false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    private static function onlineSince(): CarbonInterface
    {
        return now()->subMinutes(Client::ONLINE_THRESHOLD_MINUTES);
    }

    /**
     * Filters the cabinets on a column of their latest startup, offering the values the cabinets
     * announced last (versions newest first, other values alphabetically).
     */
    private static function latestStartupFilter(string $column, string $label, bool $versions = true): SelectFilter
    {
        return SelectFilter::make($column)
            ->label($label)
            ->options(fn (): array => self::latestStartupValues($column, $versions))
            ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                ? $query
                : $query->whereHas('latestStartup', fn (Builder $query) => $query->where($column, $data['value'])));
    }

    /** @return array<string, string> */
    private static function latestStartupValues(string $column, bool $versions): array
    {
        $values = ClientStartup::query()
            ->whereIn('id', Client::query()->where('type', ClientType::Maui)->whereNotNull('latest_startup_id')->select('latest_startup_id'))
            ->distinct()
            ->pluck($column)
            ->all();
        $versions ? usort($values, fn (string $a, string $b): int => version_compare($b, $a)) : sort($values);

        return array_combine($values, $values);
    }
}
