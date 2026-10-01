<?php

namespace App\Filament\Resources\Players\Schemas;

use App\Models\Player;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class PlayerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Player'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('pseudo_3')->label(__('Initials'))->fontFamily(FontFamily::Mono),
                        TextEntry::make('uuid')->label(__('Id'))->fontFamily(FontFamily::Mono)->copyable(),
                        TextEntry::make('status')->badge(),
                        IconEntry::make('is_public')->label(__('Public scores'))->boolean(),
                        TextEntry::make('pin_locked_at')
                            ->label(__('PIN locked'))
                            ->dateTime()
                            ->placeholder(fn (Player $record): string => __('No (:count wrong PIN in a row)', ['count' => $record->pin_failed_attempts])),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
            ]);
    }
}
