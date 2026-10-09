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

it('uses the self-hosted Pixelify Sans font', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee("url('/fonts/pixelify-sans/pixelify-sans-latin.woff2')", false);

    expect(public_path('fonts/pixelify-sans/pixelify-sans-latin.woff2'))->toBeFile()
        ->and(public_path('fonts/pixelify-sans/OFL.txt'))->toBeFile();
});

it('refills the pellets a while after the ghost has passed', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('class="refill"', false);
});
