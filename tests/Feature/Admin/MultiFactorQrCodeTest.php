<?php

use App\Filament\Auth\AppAuthentication;
use App\Models\User;
use Filament\Facades\Filament;

it('renders the setup QR code as a single, directly decodable image data URI', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create());

    $provider = AppAuthentication::make();
    $uri = $provider->generateQrCodeDataUri($provider->generateSecret());

    // SVG without imagick (our Docker image), PNG with it: both are fine, a
    // data URI wrapped in another one (D37) is not.
    expect($uri)->toMatch('#^data:image/(svg\+xml|png);base64,#');

    $payload = base64_decode(substr($uri, strpos($uri, ',') + 1), true);
    expect($payload)->not->toBeFalse()
        ->and($payload)->not->toStartWith('data:')
        ->and(str_contains($payload, '<svg') || str_starts_with($payload, "\x89PNG"))->toBeTrue();
});
