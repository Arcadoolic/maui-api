<?php

use App\View\Components\PixelSprite;
use Illuminate\Support\Facades\Blade;

it('renders one crisp rect per horizontal run of the same colour', function () {
    $html = Blade::render('<x-pixel-sprite :rows="$rows" :palette="$palette" />', [
        'rows' => ['##.w', '.ww#'],
        'palette' => ['#' => 'body', 'w' => 'eye'],
    ]);

    expect($html)
        ->toContain('viewBox="0 0 4 2"')
        ->toContain('shape-rendering="crispEdges"')
        ->toContain('<rect x="0" y="0" width="2" height="1" class="body"/>')
        ->toContain('<rect x="3" y="0" width="1" height="1" class="eye"/>')
        ->toContain('<rect x="1" y="1" width="2" height="1" class="eye"/>')
        ->toContain('<rect x="3" y="1" width="1" height="1" class="body"/>')
        ->and(substr_count($html, '<rect'))->toBe(4);
});

it('rejects rows of different widths', function () {
    new PixelSprite(['##', '#'], ['#' => 'body']);
})->throws(InvalidArgumentException::class);

it('rejects a pixel missing from the palette', function () {
    new PixelSprite(['#x'], ['#' => 'body']);
})->throws(InvalidArgumentException::class);
