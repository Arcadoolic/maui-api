<?php

use App\Models\User;
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
