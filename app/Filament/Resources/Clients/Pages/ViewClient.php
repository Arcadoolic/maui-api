<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Enums\ClientType;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Services\ClientAdministration;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

/**
 * Client detail and back office operations. Secrets (invitation link, service
 * token) are shown once in the "showSecret" modal, never stored in the session.
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->inviteAction(),
            $this->renewAction(),
            $this->issueServiceTokenAction(),
            $this->resetBindingAction(),
            $this->disableAction(),
            $this->enableAction(),
            EditAction::make(),
        ];
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('Invite'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (): bool => $this->client()->type === ClientType::Maui && ! $this->client()->hasCredentials())
            ->requiresConfirmation()
            ->modalDescription(__('Creates a single-use link, valid :hours hours, to send to the cabinet owner. A previous pending link stops working.', ['hours' => config('maui.invitation_ttl_hours')]))
            ->action(fn () => $this->showSecret(
                __('Invitation link'),
                app(ClientAdministration::class)->invite($this->client())->url,
                __('Send this link to the owner. It is shown only once.'),
            ));
    }

    public function renewAction(): Action
    {
        return Action::make('renew')
            ->label(__('Renew credentials'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => $this->client()->type === ClientType::Maui && $this->client()->hasCredentials())
            ->requiresConfirmation()
            ->modalDescription(__('Creates a renewal link. The current credentials keep working until the new ones are retrieved, then the machine binding is reset. If the credentials are compromised, disable the cabinet first.'))
            ->action(fn () => $this->showSecret(
                __('Renewal link'),
                app(ClientAdministration::class)->renew($this->client())->url,
                __('Send this link to the owner. It is shown only once.'),
            ));
    }

    public function issueServiceTokenAction(): Action
    {
        return Action::make('issueServiceToken')
            ->label(fn (): string => $this->client()->hasCredentials() ? __('Replace token') : __('Issue token'))
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (): bool => $this->client()->type === ClientType::Service)
            ->requiresConfirmation()
            ->modalDescription(__('Issues a new token. Any previous token of this account stops working immediately.'))
            ->action(fn () => $this->showSecret(
                __('Service token'),
                app(ClientAdministration::class)->issueServiceToken($this->client()),
                __('Copy it now: it is shown only once.'),
            ));
    }

    public function resetBindingAction(): Action
    {
        return Action::make('resetBinding')
            ->label(__('Reset machine binding'))
            ->icon(Heroicon::OutlinedCpuChip)
            ->color('warning')
            ->visible(fn (): bool => $this->client()->isBound())
            ->requiresConfirmation()
            ->modalDescription(__('The next machine that calls the API with these credentials will be bound to them. Use it after a hardware change or a reinstall.'))
            ->action(function (): void {
                app(ClientAdministration::class)->resetMachineBinding($this->client());
                $this->notifyDone(__('Machine binding reset'));
            });
    }

    public function disableAction(): Action
    {
        return Action::make('disable')
            ->label(__('Disable'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (): bool => $this->client()->isActive())
            ->requiresConfirmation()
            ->modalDescription(__('Every API call from this client is refused until it is enabled again. Its credentials are kept.'))
            ->action(function (): void {
                app(ClientAdministration::class)->disable($this->client());
                $this->notifyDone(__('Client disabled'));
            });
    }

    public function enableAction(): Action
    {
        return Action::make('enable')
            ->label(__('Enable'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (): bool => ! $this->client()->isActive())
            ->requiresConfirmation()
            ->action(function (): void {
                app(ClientAdministration::class)->enable($this->client());
                $this->notifyDone(__('Client enabled'));
            });
    }

    /**
     * Not in the header: only opened by replaceMountedAction() after an action
     * produced a secret. The secret lives in the Livewire component state for
     * the duration of the modal only.
     */
    public function showSecretAction(): Action
    {
        return Action::make('showSecret')
            ->modalHeading(fn (array $arguments): string => $arguments['title'] ?? '')
            ->modalDescription(fn (array $arguments): string => $arguments['description'] ?? '')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('secret')
                    ->hiddenLabel()
                    ->state($arguments['secret'] ?? '')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    private function showSecret(string $title, string $secret, string $description): void
    {
        $this->client()->refresh();
        $this->replaceMountedAction('showSecret', ['title' => $title, 'secret' => $secret, 'description' => $description]);
    }

    private function notifyDone(string $title): void
    {
        $this->client()->refresh();
        Notification::make()->title($title)->success()->send();
    }

    private function client(): Client
    {
        $record = $this->getRecord();
        assert($record instanceof Client);

        return $record;
    }
}
