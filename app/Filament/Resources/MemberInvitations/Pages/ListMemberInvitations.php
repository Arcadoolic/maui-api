<?php

namespace App\Filament\Resources\MemberInvitations\Pages;

use App\Filament\Resources\MemberInvitations\MemberInvitationResource;
use App\Services\Members\MemberAdministration;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

/**
 * The link of a new invitation is only known when it is created: shown once,
 * as the secrets of a client are (ViewClient).
 */
class ListMemberInvitations extends ListRecords
{
    protected static string $resource = MemberInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->inviteAction(),
        ];
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('New invitation'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->modalDescription(__('Creates a link to the hiscores front. A Discord account that opens it and logs in becomes a member.'))
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('Who the link is for, e.g. a name or "Discord server".')),
                TextInput::make('max_uses')
                    ->label(__('Accounts'))
                    ->integer()
                    ->minValue(1)
                    ->default(1)
                    ->helperText(__('How many Discord accounts can use the link. Empty: no limit.')),
                TextInput::make('expires_in_days')
                    ->label(__('Valid for (days)'))
                    ->integer()
                    ->minValue(1)
                    ->default(7)
                    ->helperText(__('Empty: until it is revoked.')),
            ])
            ->action(function (array $data): void {
                $issued = app(MemberAdministration::class)->invite(
                    (string) $data['label'],
                    filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null,
                    filled($data['expires_in_days'] ?? null) ? now()->addDays((int) $data['expires_in_days']) : null,
                );

                $this->replaceMountedAction('showSecret', ['secret' => $issued->url]);
            });
    }

    /** Not in the header: only opened by replaceMountedAction() once a link is created. */
    public function showSecretAction(): Action
    {
        return Action::make('showSecret')
            ->modalHeading(__('Invitation link'))
            ->modalDescription(__('Send this link to the future members. It is shown only once.'))
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
}
