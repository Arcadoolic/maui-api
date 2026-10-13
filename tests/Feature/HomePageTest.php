<?php

it('shows the arcade chase animation instead of the Laravel welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('class="runner pacman"', false)
        ->assertSee('class="runner ghost"', false)
        ->assertDontSee('laravel.com');
});

it('shows no title above the animation', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('<h1', false);
});

it('eats pellets only between the ghost and Pac-Man so they come back behind the ghost', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('class="eaten"', false);
});

it('loads no external asset', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('https://', false)
        ->assertDontSee('<script', false);
});

it('uses the self-hosted Press Start 2P font, as the hiscores front does', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee("url('/fonts/press-start-2p/press-start-2p-latin.woff2')", false);

    expect(public_path('fonts/press-start-2p/press-start-2p-latin.woff2'))->toBeFile()
        ->and(public_path('fonts/press-start-2p/OFL.txt'))->toBeFile();
});

it('refills the pellets a while after the ghost has passed', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('class="refill"', false);
});

it('draws Pac-Man and the ghost as pixel sprites with several frames', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'class="frame"'))->toBe(6);
});

it('draws the 16x16 arcade sprites shared with the hiscores front', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'viewBox="0 0 16 16"'))->toBe(6)
        ->and($html)->toContain('--color-puck: #ffff00')
        ->and($html)->toContain('--color-blinky: #ff0000');
});
