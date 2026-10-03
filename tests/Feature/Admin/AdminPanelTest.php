<?php

use App\Models\User;
use App\Support\AdminMfa;
use Filament\Facades\Filament;

it('sends guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('forces an admin without multi-factor authentication to set it up', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('lets an admin with multi-factor authentication in', function () {
    $this->actingAs(User::factory()->withAppAuthentication()->create());

    $this->get('/admin')->assertOk();
    $this->get('/admin/clients')->assertOk();
});

it('offers no public registration', function () {
    $this->get('/admin/register')->assertNotFound();
});

describe('multi-factor authentication settings (D55)', function () {
    it('labels the authenticator account after the server', function () {
        config(['app.name' => 'MAUI-API', 'app.url' => 'https://api.maui.staging.afronob.com']);
        expect(AdminMfa::label())->toBe('MAUI-API (api.maui.staging.afronob.com)');

        config(['app.url' => 'http://localhost:8080']);
        expect(AdminMfa::label())->toBe('MAUI-API (localhost:8080)');

        config(['maui.admin_mfa.label' => 'MAUI-API STG']);
        expect(AdminMfa::label())->toBe('MAUI-API STG');
    });

    it('puts that label in the setup of the authenticator app', function () {
        config(['app.name' => 'MAUI-API', 'app.url' => 'https://api.maui.staging.afronob.com']);

        $provider = collect(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders())->first();

        expect($provider->getBrandName())->toBe('MAUI-API (api.maui.staging.afronob.com)');
    });

    // The routes are built when the application boots, from the environment: the setting is
    // checked here, and with MAUI_ADMIN_MFA=false in a real container (D55).
    it('asks for no second factor once turned off outside production', function () {
        config(['maui.admin_mfa.enabled' => false]);
        $panel = Filament::getPanel('admin');

        expect(AdminMfa::isEnabled())->toBeFalse()
            ->and($panel->isMultiFactorAuthenticationRequired())->toBeFalse()
            ->and($panel->getMultiFactorAuthenticationProviders())->toBe([]);
    });

    it('keeps it on in production whatever the setting says', function () {
        config(['maui.admin_mfa.enabled' => false]);
        app()->detectEnvironment(fn () => 'production');

        expect(AdminMfa::isEnabled())->toBeTrue();
    });
});
