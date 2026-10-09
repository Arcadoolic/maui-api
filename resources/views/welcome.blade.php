<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'MAUI API') }}</title>
        <style>
            :root {
                --size: 48px;
                --duration: 6s;
                --gap: 72px;
                --bg: #000;
                --maze: #2121de;
                --pellet: #ffb8ae;
                --pacman: #ffff00;
                --ghost: #ff0000;
            }

            * { box-sizing: border-box; margin: 0; }

            body {
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: var(--bg);
                color: #fff;
                font-family: ui-monospace, "Courier New", monospace;
                overflow: hidden;
            }

            main { width: 100%; text-align: center; }

            h1 {
                color: var(--pacman);
                font-size: clamp(1.5rem, 6vw, 3rem);
                letter-spacing: .3em;
                margin-bottom: 3rem;
            }

            .corridor {
                position: relative;
                height: calc(var(--size) + 32px);
                border-block: 4px double var(--maze);
            }

            .pellets {
                position: absolute;
                inset: 0;
                background: radial-gradient(circle, var(--pellet) 4px, transparent 5px) 0 50% / 32px 100% repeat-x;
                animation: eat var(--duration) linear infinite;
            }

            .runner {
                position: absolute;
                top: 16px;
                width: var(--size);
                height: var(--size);
            }

            .pacman { animation: run var(--duration) linear infinite; }

            .pacman::before,
            .pacman::after {
                content: "";
                position: absolute;
                left: 0;
                width: 100%;
                height: 50%;
                background: var(--pacman);
            }

            .pacman::before {
                top: 0;
                border-radius: var(--size) var(--size) 0 0;
                transform-origin: 50% 100%;
                animation: chomp-top .25s ease-in-out infinite alternate;
            }

            .pacman::after {
                bottom: 0;
                border-radius: 0 0 var(--size) var(--size);
                transform-origin: 50% 0;
                animation: chomp-bottom .25s ease-in-out infinite alternate;
            }

            .ghost {
                background: var(--ghost);
                border-radius: var(--size) var(--size) 0 0;
                animation: chase var(--duration) linear infinite, float .3s ease-in-out infinite alternate;
            }

            /* Wavy skirt: the background colour bites three notches out of the bottom. */
            .ghost::after {
                content: "";
                position: absolute;
                left: 0;
                bottom: -1px;
                width: 100%;
                height: 8px;
                background: radial-gradient(circle at 50% 100%, var(--bg) 4px, transparent 5px) 0 0 / 16px 8px repeat-x;
            }

            .eye {
                position: absolute;
                top: 12px;
                width: 14px;
                height: 16px;
                background: #fff;
                border-radius: 50%;
            }

            .eye::after {
                content: "";
                position: absolute;
                top: 5px;
                right: 1px;
                width: 7px;
                height: 7px;
                background: var(--maze);
                border-radius: 50%;
            }

            .eye.left { left: 8px; }
            .eye.right { left: 26px; }

            .insert-coin {
                margin-top: 3rem;
                font-size: .9rem;
                letter-spacing: .2em;
                animation: blink 1s steps(1) infinite;
            }

            @keyframes run {
                from { left: calc(-1 * var(--size)); }
                to { left: calc(100% + var(--gap) + var(--size)); }
            }

            @keyframes chase {
                from { left: calc(-1 * var(--size) - var(--gap)); }
                to { left: calc(100% + var(--size)); }
            }

            /* Pellets vanish right behind Pac-Man's mouth, in step with "run". */
            @keyframes eat {
                from { clip-path: inset(0 0 0 calc(-0.5 * var(--size))); }
                to { clip-path: inset(0 0 0 calc(100% + var(--gap) + 1.5 * var(--size))); }
            }

            @keyframes chomp-top {
                from { transform: rotate(0); }
                to { transform: rotate(-40deg); }
            }

            @keyframes chomp-bottom {
                from { transform: rotate(0); }
                to { transform: rotate(40deg); }
            }

            @keyframes float {
                from { transform: translateY(0); }
                to { transform: translateY(-3px); }
            }

            @keyframes blink {
                50% { opacity: 0; }
            }

            @media (prefers-reduced-motion: reduce) {
                *, *::before, *::after { animation: none !important; }
                .pacman { left: calc(50% + var(--gap) / 2); }
                .ghost { left: calc(50% - var(--gap) / 2 - var(--size)); }
            }
        </style>
    </head>
    <body>
        <main>
            <h1>MAUI</h1>
            <div class="corridor" aria-hidden="true">
                <div class="pellets"></div>
                <div class="runner ghost"><span class="eye left"></span><span class="eye right"></span></div>
                <div class="runner pacman"></div>
            </div>
            <p class="insert-coin">INSERT COIN</p>
        </main>
    </body>
</html>
