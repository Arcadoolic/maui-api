<?php

use App\Filament\Auth\AppAuthentication;
use App\Models\User;
use Filament\Facades\Filament;

it('renders the setup QR code as a single, directly decodable SVG data URI', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());

    $provider = AppAuthentication::make();
    $uri = $provider->generateQrCodeDataUri($provider->generateSecret());

    $prefix = 'data:image/svg+xml;base64,';
    expect($uri)->toStartWith($prefix)
        ->and(base64_decode(substr($uri, strlen($prefix)), true))->toContain('<svg');
});
