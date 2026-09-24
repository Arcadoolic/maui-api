<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientType;
use App\Models\Client;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Identity'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')->fontFamily(FontFamily::Mono),
                        TextEntry::make('public_key')->label(__('Key'))->fontFamily(FontFamily::Mono)->copyable(),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make(__('Cabinet'))
                    ->columns(2)
                    ->visible(fn (Client $record): bool => $record->type === ClientType::Maui)
                    ->schema([
                        IconEntry::make('online')
                            ->state(fn (Client $record): bool => $record->isOnline())
                            ->boolean(),
                        TextEntry::make('last_heartbeat_at')->since()->placeholder(__('Never')),
                        TextEntry::make('bound_at')->label(__('Bound to a machine'))->dateTime()->placeholder(__('Not bound')),
                        TextEntry::make('latestStartup.maui_version')->label(__('MAUI version'))->placeholder('-'),
                        TextEntry::make('latestStartup.mame_version')->label(__('MAME version'))->placeholder('-'),
                        TextEntry::make('latestStartup.os')
                            ->label(__('OS'))
                            ->formatStateUsing(fn (string $state, Client $record): string => $state.' '.$record->latestStartup?->os_version)
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
