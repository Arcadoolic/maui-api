<?php

namespace App\Filament\Resources\Members\Tables;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Services\Members\MemberAdministration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['players', 'invitation']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('username')->label(__('Discord account'))->searchable()->sortable(),
                TextColumn::make('display_name')->label(__('Display name'))->searchable()->placeholder('-'),
                TextColumn::make('status')->badge(),
                TextColumn::make('players.pseudo_3')->label(__('Players'))->badge()->fontFamily('mono')->placeholder(__('None')),
                TextColumn::make('invitation.label')->label(__('Invitation'))->placeholder('-'),
                TextColumn::make('last_login_at')->label(__('Last login'))->since()->sortable(),
                TextColumn::make('created_at')->label(__('Joined'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(MemberStatus::class),
            ])
            ->recordActions([
                Action::make('disable')
                    ->label(__('Disable'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Member $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('The member is logged out of the hiscores front and can no longer log in. Its players and their scores are unchanged.'))
                    ->action(function (Member $record): void {
                        app(MemberAdministration::class)->disable($record);
                        Notification::make()->title(__('Member disabled'))->success()->send();
                    }),
                Action::make('enable')
                    ->label(__('Enable'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Member $record): bool => ! $record->isActive())
                    ->requiresConfirmation()
                    ->action(function (Member $record): void {
                        app(MemberAdministration::class)->enable($record);
                        Notification::make()->title(__('Member enabled'))->success()->send();
                    }),
            ]);
    }
}
