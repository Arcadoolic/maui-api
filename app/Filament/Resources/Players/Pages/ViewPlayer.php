<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Player;
use App\Services\Players\PlayerAdministration;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class ViewPlayer extends ViewRecord
{
    protected static string $resource = PlayerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->showPinAction(),
            $this->regeneratePinAction(),
            $this->unlockAction(),
            $this->disableAction(),
            $this->enableAction(),
        ];
    }

    public function showPinAction(): Action
    {
        return Action::make('showPin')
            ->label(__('Show PIN'))
            ->icon(Heroicon::OutlinedEye)
            ->requiresConfirmation()
            ->modalDescription(__('For a player who lost their PIN. Every reading is recorded in the audit log.'))
            ->action(fn () => $this->showSecret(
                __('PIN of :pseudo', ['pseudo' => $this->player()->pseudo_3]),
                app(PlayerAdministration::class)->revealPin($this->player()),
            ));
    }

    public function regeneratePinAction(): Action
    {
        return Action::make('regeneratePin')
            ->label(__('New PIN'))
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('Replaces the PIN and unlocks the player. The current PIN stops working.'))
            ->action(fn () => $this->showSecret(
                __('New PIN of :pseudo', ['pseudo' => $this->player()->pseudo_3]),
                app(PlayerAdministration::class)->regeneratePin($this->player()),
            ));
    }

    /**
     * Not in the header: only opened by replaceMountedAction() once a PIN is
     * shown, as on the client page (ViewClient).
     */
    public function showSecretAction(): Action
    {
        return Action::make('showSecret')
            ->modalHeading(fn (array $arguments): string => $arguments['title'] ?? '')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('secret')
                    ->hiddenLabel()
                    ->state($arguments['secret'] ?? '')
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Large)
                    ->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    public function unlockAction(): Action
    {
        return Action::make('unlock')
            ->label(__('Unlock'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->visible(fn (): bool => $this->player()->isLocked())
            ->requiresConfirmation()
            ->modalDescription(__('Clears the lock set after too many wrong PINs. The PIN is unchanged: the player keeps using the one they have.'))
            ->action(function (): void {
                app(PlayerAdministration::class)->unlock($this->player());
                $this->notifyDone(__('Player unlocked'));
            });
    }

    public function disableAction(): Action
    {
        return Action::make('disable')
            ->label(__('Disable'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (): bool => $this->player()->isActive())
            ->requiresConfirmation()
            ->modalDescription(__('The player can no longer be linked or changed from any cabinet. Their initials stay reserved.'))
            ->action(function (): void {
                app(PlayerAdministration::class)->disable($this->player());
                $this->notifyDone(__('Player disabled'));
            });
    }

    public function enableAction(): Action
    {
        return Action::make('enable')
            ->label(__('Enable'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (): bool => ! $this->player()->isActive())
            ->requiresConfirmation()
            ->action(function (): void {
                app(PlayerAdministration::class)->enable($this->player());
                $this->notifyDone(__('Player enabled'));
            });
    }

    private function showSecret(string $title, string $secret): void
    {
        $this->player()->refresh();
        $this->replaceMountedAction('showSecret', ['title' => $title, 'secret' => $secret]);
    }

    private function notifyDone(string $title): void
    {
        $this->player()->refresh();
        Notification::make()->title($title)->success()->send();
    }

    private function player(): Player
    {
        $record = $this->getRecord();
        assert($record instanceof Player);

        return $record;
    }
}
