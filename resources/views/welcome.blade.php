<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'MAUI API') }}</title>
        <style>
            /* Self-hosted (SIL OFL 1.1, see public/fonts/pixelify-sans/OFL.txt): no request to Google. */
            @font-face {
                font-family: "Pixelify Sans";
                font-style: normal;
                font-weight: 400;
                font-display: swap;
                src: url('/fonts/pixelify-sans/pixelify-sans-latin.woff2') format("woff2");
            }

            :root {
                --size: 48px;
                --duration: 7.5s; /* 6s run across, 1.5s refill delay: see the keyframes */
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
                font-family: "Pixelify Sans", ui-monospace, "Courier New", monospace;
                overflow: hidden;
            }

            main { width: 100%; text-align: center; }

            .corridor {
                position: relative;
                height: calc(var(--size) + 32px);
                border-block: 4px double var(--maze);
            }

            .pellets,
            .refill {
                position: absolute;
                inset: 0;
                background: radial-gradient(circle, var(--pellet) 4px, transparent 5px) 0 50% / 32px 100% repeat-x;
            }

            /* Covers every pellet left of Pac-Man's mouth. */
            .eaten {
                position: absolute;
                inset-block: 0;
                width: 300vw;
                transform: translateX(-100%);
                background: var(--bg);
                animation: eat var(--duration) linear infinite;
            }

            /* Puts the pellets back up to where the ghost's centre was 1.5s ago. */
            .refill { animation: refill var(--duration) linear infinite; }

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

            /* Runners cross the screen in the first 80% of the cycle (6s of 7.5s),
               "refill" trails the ghost by the last 20% (1.5s). Change both together. */
            @keyframes run {
                from { left: calc(-1 * var(--size)); }
                80%, to { left: calc(100% + var(--gap) + var(--size)); }
            }

            @keyframes chase {
                from { left: calc(-1 * var(--size) - var(--gap)); }
                80%, to { left: calc(100% + var(--size)); }
            }

            /* Pac-Man's centre (run + size / 2). */
            @keyframes eat {
                from { left: calc(-0.5 * var(--size)); }
                80%, to { left: calc(100% + var(--gap) + 1.5 * var(--size)); }
            }

            /* Ghost's centre (chase + size / 2), 20% late; the right inset is 100% minus it. */
            @keyframes refill {
                from, 20% { clip-path: inset(0 calc(100% + var(--gap) + 0.5 * var(--size)) 0 0); }
                to { clip-path: inset(0 calc(-1.5 * var(--size)) 0 0); }
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
                .eaten, .refill { display: none; }
                .pacman { left: calc(50% + var(--gap) / 2); }
                .ghost { left: calc(50% - var(--gap) / 2 - var(--size)); }
            }
        </style>
    </head>
    <body>
        <main>
            <div class="corridor" aria-hidden="true">
                <div class="pellets"></div>
                <div class="eaten"></div>
                <div class="refill"></div>
                <div class="runner ghost"><span class="eye left"></span><span class="eye right"></span></div>
                <div class="runner pacman"></div>
            </div>
            <p class="insert-coin">INSERT COIN</p>
        </main>
    </body>
</html>
