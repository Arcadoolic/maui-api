<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'MAUI API') }}</title>
        <style>
            /* Same font as the hiscores front (maui-hifront), self-hosted (SIL OFL 1.1,
               see public/fonts/press-start-2p/OFL.txt): no request to Google. */
            @font-face {
                font-family: "Press Start 2P";
                font-style: normal;
                font-weight: 400;
                font-display: swap;
                src: url('/fonts/press-start-2p/press-start-2p-latin.woff2') format("woff2");
            }

            /* Colour names and values of maui-hifront src/style.css. */
            :root {
                --color-screen: #000000;
                --color-maze: #2121de;
                --color-pellet: #ffb8ae;
                --color-puck: #ffff00;
                --color-blinky: #ff0000;
                --color-ink: #dedede;
                --color-eye-white: #dedeff;
                --color-pupil: #2121ff;

                /* The game's proportions, three times its size: 16-pixel sprites,
                   a 2-pixel pellet every 8 pixels. */
                --size: 48px;
                --pellet: 6px;
                --pellet-step: 24px;
                --pellet-x: 9px;
                --gap: 72px;
                --duration: 7.5s; /* 6s run across, 1.5s refill delay: see the keyframes */
            }

            * { box-sizing: border-box; margin: 0; }

            body {
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: var(--color-screen);
                color: var(--color-ink);
                font-family: "Press Start 2P", ui-monospace, "Courier New", monospace;
                overflow: hidden;
            }

            main { width: 100%; text-align: center; }

            .corridor {
                position: relative;
                height: calc(var(--size) + 32px);
                border-block: 4px double var(--color-maze);
            }

            .pellets,
            .refill {
                position: absolute;
                inset: 0;
                background: repeating-linear-gradient(90deg, var(--color-pellet) 0 var(--pellet), transparent var(--pellet) var(--pellet-step)) var(--pellet-x) 50% / 100% var(--pellet) no-repeat;
            }

            /* Covers every pellet left of Pac-Man's mouth. */
            .eaten {
                position: absolute;
                inset-block: 0;
                width: 300vw;
                transform: translateX(-100%);
                background: var(--color-screen);
                animation: eat var(--duration) linear infinite;
            }

            /* Puts the pellets back up to where the ghost's centre was 1.5s ago. Starts
               off screen so its width stays positive; the background is shifted back. */
            .refill {
                inset: 0 auto 0 calc(-1 * var(--gap) - var(--size));
                background-position-x: calc(var(--pellet-x) + var(--gap) + var(--size));
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

            .pacman .body { fill: var(--color-puck); }
            .ghost .body { fill: var(--color-blinky); }
            .ghost .eye-white { fill: var(--color-eye-white); }
            .ghost .pupil { fill: var(--color-pupil); }

            .insert-coin {
                margin-top: 3rem;
                font-size: .75rem;
                letter-spacing: .1em;
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
                // The arcade sprites, as maui-hifront draws them: grids copied from its
                // src/sprites.ts (PACMAN_RIGHT, GHOST_RIGHT). Keep the two in step.
                $pacman = ['#' => 'body'];
                $pacmanHalfOpen = [
                    '................',
                    '.....#####......',
                    '...#########....',
                    '..###########...',
                    '..###########...',
                    '.##########.....',
                    '.#######........',
                    '.####...........',
                    '.#######........',
                    '.##########.....',
                    '..###########...',
                    '..###########...',
                    '...#########....',
                    '.....#####......',
                    '................',
                    '................',
                ];
                $ghost = ['#' => 'body', 'w' => 'eye-white', 'p' => 'pupil'];
                $ghostBody = [
                    '................',
                    '......####......',
                    '....########....',
                    '...##########...',
                    '..###ww####ww#..',
                    '..##wwww##wwww..',
                    '..##wwpp##wwpp..',
                    '.###wwpp##wwpp#.',
                    '.####ww####ww##.',
                    '.##############.',
                    '.##############.',
                    '.##############.',
                    '.##############.',
                ];
            @endphp
            <div class="corridor" aria-hidden="true">
                <div class="pellets"></div>
                <div class="eaten"></div>
                <div class="refill"></div>
                <div class="runner ghost">
                    <x-pixel-sprite :rows="[...$ghostBody, '.##.###..###.##.', '.#...##..##...#.', '................']" :palette="$ghost" />
                    <x-pixel-sprite :rows="[...$ghostBody, '.####.####.####.', '..##...##...##..', '................']" :palette="$ghost" />
                </div>
                {{-- The game's cycle (shut, half open, wide open, half open), started on half open
                     so that the still frame shown without motion reads as Pac-Man. --}}
                <div class="runner pacman">
                    <x-pixel-sprite :rows="$pacmanHalfOpen" :palette="$pacman" />
                    <x-pixel-sprite :rows="[
                        '................',
                        '.....#####......',
                        '...#######......',
                        '..#######.......',
                        '..######........',
                        '.######.........',
                        '.#####..........',
                        '.####...........',
                        '.#####..........',
                        '.######.........',
                        '..######........',
                        '..#######.......',
                        '...#######......',
                        '.....#####......',
                        '................',
                        '................',
                    ]" :palette="$pacman" />
                    <x-pixel-sprite :rows="$pacmanHalfOpen" :palette="$pacman" />
                    <x-pixel-sprite :rows="[
                        '................',
                        '.....#####......',
                        '...#########....',
                        '..###########...',
                        '..###########...',
                        '.#############..',
                        '.#############..',
                        '.#############..',
                        '.#############..',
                        '.#############..',
                        '..###########...',
                        '..###########...',
                        '...#########....',
                        '.....#####......',
                        '................',
                        '................',
                    ]" :palette="$pacman" />
                </div>
            </div>
            <p class="insert-coin">INSERT COIN</p>
        </main>
    </body>
</html>
