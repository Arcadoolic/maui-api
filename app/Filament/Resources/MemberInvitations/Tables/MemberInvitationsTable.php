<?php

namespace App\Filament\Resources\MemberInvitations\Tables;

use App\Models\MemberInvitation;
use App\Services\Members\MemberAdministration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MemberInvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('members'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('state')
                    ->state(fn (MemberInvitation $record): string => $record->state())
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'revoked' => __('Revoked'),
                        'expired' => __('Expired'),
                        'used_up' => __('Used up'),
                        default => __('Usable'),
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'usable' ? 'success' : 'gray'),
                TextColumn::make('uses')
                    ->label(__('Uses'))
                    ->formatStateUsing(fn (MemberInvitation $record): string => $record->uses.' / '.($record->max_uses ?? '∞')),
                TextColumn::make('members_count')->label(__('Members'))->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder(__('Never'))->sortable(),
                TextColumn::make('creator.name')->label(__('Created by')),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (MemberInvitation $record): bool => $record->isUsable())
                    ->requiresConfirmation()
                    ->modalDescription(__('The link stops working. The members it already let in keep their access.'))
                    ->action(function (MemberInvitation $record): void {
                        app(MemberAdministration::class)->revoke($record);
                        Notification::make()->title(__('Invitation revoked'))->success()->send();
                    }),
            ]);
    }
}
