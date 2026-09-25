<?php

namespace App\Filament\Pages\Auth;

use DateTimeZone;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Admin profile, with the display timezone of the back office (D41).
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        $timezones = DateTimeZone::listIdentifiers();

        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                Select::make('timezone')
                    ->label(__('Timezone'))
                    ->helperText(__('Dates in the back office are shown in this timezone.'))
                    ->options(array_combine($timezones, $timezones))
                    ->searchable()
                    ->required()
                    ->rule(Rule::in($timezones)),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }
}
