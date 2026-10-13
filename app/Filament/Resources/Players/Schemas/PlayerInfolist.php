<?php

namespace App\Filament\Resources\Players\Schemas;

use App\Models\Member;
use App\Models\Player;
use App\Services\Players\PlayerAvatars;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Facades\Storage;

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
                        TextEntry::make('originClient.name')
                            ->label(__('Created on'))
                            ->placeholder(__('Unknown: only admins can issue a new PIN'))
                            ->helperText(__('The only cabinet that can issue a new PIN.')),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make(__('Avatar'))
                    ->columns(2)
                    ->schema([
                        // Kept on a private disk: shown from its bytes, 256 KB at most.
                        ImageEntry::make('avatar')
                            ->hiddenLabel()
                            ->state(fn (Player $record): ?string => PlayerAvatars::exists($record)
                                ? 'data:image/png;base64,'.base64_encode((string) Storage::disk(PlayerAvatars::DISK)->get(PlayerAvatars::path($record)))
                                : null)
                            ->imageSize(128)
                            ->placeholder(__('None')),
                        TextEntry::make('avatar_by_admin')
                            ->label(__('Set by'))
                            ->state(fn (Player $record): ?string => match (true) {
                                $record->avatar_hash === null => null,
                                // The flag says "not the origin cabinet's": the audit trail says whose (D85).
                                $record->avatar_by_admin => $record->activitiesAsSubject()
                                    ->where('event', 'player.avatar_changed')->latest('id')->first()?->causer instanceof Member
                                    ? (string) __('Its member, on the hiscores front')
                                    : (string) __('An administrator'),
                                default => (string) __('The cabinet the player was created on'),
                            })
                            ->placeholder('-')
                            ->helperText(__('A picture set here or by the member replaces the one of that cabinet, until the cabinet changes it again.')),
                    ]),
            ]);
    }
}
