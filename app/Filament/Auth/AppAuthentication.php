<?php

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use SensitiveParameter;

/**
 * Works around a Filament 5.8 / pragmarx/google2fa-qrcode 4 mismatch: with
 * bacon/bacon-qr-code and without imagick, Filament base64-encodes the value
 * returned by google2fa-qrcode as if it were raw SVG, but version 4 already
 * returns a complete data URI. The QR code image ends up double-encoded and
 * broken (docs/DECISIONS.md D37). Unwraps it only when it is double-encoded,
 * so it becomes a no-op once Filament fixes it.
 */
class AppAuthentication extends BaseAppAuthentication
{
    private const SVG_DATA_URI_PREFIX = 'data:image/svg+xml;base64,';

    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        $uri = parent::generateQrCodeDataUri($secret);

        if (! str_starts_with($uri, self::SVG_DATA_URI_PREFIX)) {
            return $uri;
        }

        $inner = base64_decode(substr($uri, strlen(self::SVG_DATA_URI_PREFIX)), true);

        return is_string($inner) && str_starts_with($inner, 'data:image/') ? $inner : $uri;
    }
}
