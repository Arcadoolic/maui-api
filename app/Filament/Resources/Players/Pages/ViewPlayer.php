<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Models\Client;
use App\Models\Player;
use App\Services\Players\AvatarImage;
use App\Services\Players\PlayerAdministration;
use App\Services\Players\PlayerAvatars;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ViewPlayer extends ViewRecord
{
    protected static string $resource = PlayerResource::class;

    /** Kilobytes: a photo straight from a phone; PHP takes 16 MB (docker/php). */
    private const MAX_UPLOAD_KB = 12 * 1024;

    protected function getHeaderActions(): array
    {
        return [
            $this->showPinAction(),
            $this->regeneratePinAction(),
            $this->unlockAction(),
            $this->setOriginAction(),
            $this->changeAvatarAction(),
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

    /** Which cabinet may issue a new PIN for this player (D54). */
    public function setOriginAction(): Action
    {
        return Action::make('setOrigin')
            ->label(__('Origin cabinet'))
            ->icon(Heroicon::OutlinedHome)
            ->color('gray')
            ->modalDescription(__('The only cabinet that can issue a new PIN for this player. Without one, only administrators can.'))
            ->fillForm(fn (): array => ['origin_client_id' => $this->player()->origin_client_id])
            ->schema([
                Select::make('origin_client_id')
                    ->label(__('Cabinet'))
                    ->options(fn (): array => $this->player()->clients()->orderBy('name')->pluck('clients.name', 'clients.id')->all())
                    ->placeholder(__('None: administrators only')),
            ])
            ->action(function (array $data): void {
                $origin = filled($data['origin_client_id'] ?? null)
                    ? Client::query()->whereKey($data['origin_client_id'])->first()
                    : null;
                app(PlayerAdministration::class)->setOrigin($this->player(), $origin);
                $this->notifyDone(__('Origin cabinet changed'));
            });
    }

    /** An administrator's picture for the player (D83), which every cabinet then shows. */
    public function changeAvatarAction(): Action
    {
        return Action::make('changeAvatar')
            ->label(__('Change avatar'))
            ->icon(Heroicon::OutlinedPhoto)
            ->color('gray')
            ->modalDescription(__('Replaces the player\'s picture on every cabinet and on the hiscores front, the cabinet it was created on included. That cabinet can change it again afterwards.'))
            ->schema([
                FileUpload::make('avatar')
                    ->label(__('Image'))
                    ->helperText(__('PNG, JPEG or WebP, a photo included, :side pixels wide and high at least. Use the pencil on the picture to choose the square to keep (:side x :side at least); without it, the centre is kept. The picture is then scaled down.', ['side' => AvatarImage::MIN_SIDE]))
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    // The square is chosen in the browser, before the upload; what arrives is
                    // made to fit all the same (AvatarImage).
                    ->imageEditor()
                    ->imageEditorAspectRatioOptions(['1:1'])
                    ->maxSize(self::MAX_UPLOAD_KB)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, Action $action): void {
                $file = $data['avatar'] ?? null;
                try {
                    app(PlayerAvatars::class)->storeByAdmin($this->player(), $file instanceof TemporaryUploadedFile ? (string) $file->get() : '');
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();
                }
                $this->notifyDone(__('Avatar changed'));
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
