<?php

it('shows the arcade chase animation instead of the Laravel welcome page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('class="runner pacman"', false)
        ->assertSee('class="runner ghost"', false)
        ->assertDontSee('laravel.com');
});

it('loads no external asset', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('https://', false)
        ->assertDontSee('<script', false);
});
