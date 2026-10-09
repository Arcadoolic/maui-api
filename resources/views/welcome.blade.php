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
                /* 2x2 sprite pixels every 8 sprite pixels. */
                background: repeating-linear-gradient(90deg, var(--pellet) 0 8px, transparent 8px 32px) 12px 50% / 100% 8px no-repeat;
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

            /* Puts the pellets back up to where the ghost's centre was 1.5s ago. Starts
               off screen so its width stays positive; the background is shifted back. */
            .refill {
                inset: 0 auto 0 calc(-1 * var(--gap) - var(--size));
                background-position-x: calc(12px + var(--gap) + var(--size));
                animation: refill var(--duration) linear infinite;
            }

            .runner {
                position: absolute;
                top: 16px;
                width: var(--size);
                height: var(--size);
            }

            .pacman { animation: run var(--duration) linear infinite; }

            .ghost { animation: chase var(--duration) linear infinite; }

            /* Sprite frames are stacked; each one is shown in turn, the first one alone without motion. */
            .frame {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                visibility: hidden;
            }

            .frame:first-child { visibility: visible; }

            .pacman .frame { animation: show-quarter .4s linear infinite; }
            .pacman .frame:nth-child(2) { animation-delay: -.3s; }
            .pacman .frame:nth-child(3) { animation-delay: -.2s; }
            .pacman .frame:nth-child(4) { animation-delay: -.1s; }

            .ghost .frame { animation: show-half .3s linear infinite; }
            .ghost .frame:nth-child(2) { animation-delay: -.15s; }

            .pacman .skin { fill: var(--pacman); }
            .pacman .eye { fill: var(--bg); }
            .ghost .skin { fill: var(--ghost); }
            .ghost .eye-white { fill: #fff; }
            .ghost .pupil { fill: var(--maze); }

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

            /* Ghost's centre (chase + size / 2), 20% late, minus the refill's left offset. */
            @keyframes refill {
                from, 20% { width: calc(0.5 * var(--size)); }
                to { width: calc(100% + var(--gap) + 2.5 * var(--size)); }
            }

            @keyframes show-quarter {
                from { visibility: visible; }
                25%, to { visibility: hidden; }
            }

            @keyframes show-half {
                from { visibility: visible; }
                50%, to { visibility: hidden; }
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
            @php
                // Original sprites (12x12, scaled x4), a nod to the arcade ones, not a copy.
                $pacman = ['#' => 'skin', 'o' => 'eye'];
                $pacmanHalfOpen = [
                    '....####....',
                    '..########..',
                    '.#####o####.',
                    '.#########..',
                    '#########...',
                    '#######.....',
                    '#######.....',
                    '#########...',
                    '.#########..',
                    '.##########.',
                    '..########..',
                    '....####....',
                ];
                $ghost = ['#' => 'skin', 'w' => 'eye-white', 'p' => 'pupil'];
                $ghostBody = [
                    '....####....',
                    '..########..',
                    '.##########.',
                    '.#www##www#.',
                    '##wpp##wpp##',
                    '##wpp##wpp##',
                    '##www##www##',
                    '############',
                    '############',
                    '############',
                    '############',
                ];
            @endphp
            <div class="corridor" aria-hidden="true">
                <div class="pellets"></div>
                <div class="eaten"></div>
                <div class="refill"></div>
                <div class="runner ghost">
                    <x-pixel-sprite :rows="[...$ghostBody, '##..##..##..']" :palette="$ghost" />
                    <x-pixel-sprite :rows="[...$ghostBody, '..##..##..##']" :palette="$ghost" />
                </div>
                <div class="runner pacman">
                    <x-pixel-sprite :rows="[
                        '....####....',
                        '..#######...',
                        '.#####o#....',
                        '.######.....',
                        '######......',
                        '#####.......',
                        '#####.......',
                        '######......',
                        '.######.....',
                        '.#######....',
                        '..#######...',
                        '....####....',
                    ]" :palette="$pacman" />
                    <x-pixel-sprite :rows="$pacmanHalfOpen" :palette="$pacman" />
                    <x-pixel-sprite :rows="[
                        '....####....',
                        '..########..',
                        '.#####o####.',
                        '.##########.',
                        '############',
                        '############',
                        '############',
                        '############',
                        '.##########.',
                        '.##########.',
                        '..########..',
                        '....####....',
                    ]" :palette="$pacman" />
                    <x-pixel-sprite :rows="$pacmanHalfOpen" :palette="$pacman" />
                </div>
            </div>
            <p class="insert-coin">INSERT COIN</p>
        </main>
    </body>
</html>
