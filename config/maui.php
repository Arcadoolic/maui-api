<?php

return [

    /*
    | Hours during which an invitation link can be claimed (docs/PLAN.md 1.3).
    */
    'invitation_ttl_hours' => (int) env('MAUI_INVITATION_TTL_HOURS', 72),

    /*
    | Base URL of the starting-pack repository served to the cabinets of this
    | server, announced by GET /api/v1/repository (docs/DECISIONS.md D46).
    | Unset: this server has no repository.
    */
    'repository_url' => env('MAUI_REPOSITORY_URL'),

    /*
    | Timezone used to display dates in the back office when the admin has not
    | chosen one (or nobody is logged in). Storage always stays in UTC.
    */
    'admin_default_timezone' => env('MAUI_ADMIN_DEFAULT_TIMEZONE', 'Europe/Paris'),

    /*
    | Multi-factor authentication of the back office (docs/DECISIONS.md D55).
    | `enabled`: false turns it off, outside production only (it stays on in
    | production whatever this says). `label`: name of the account in the
    | authenticator app; unset, the application name and the host of APP_URL.
    */
    'admin_mfa' => [
        'enabled' => (bool) env('MAUI_ADMIN_MFA', true),
        'label' => env('MAUI_ADMIN_MFA_LABEL'),
    ],

    /*
    | Word lists for generated cabinet names, e.g. "glitchy_pac_man"
    | (docs/PLAN.md 1.1). Lowercase, snake_case words only.
    */
    'names' => [
        'adjectives' => [
            'glitchy', 'laggy', 'pixelated', 'blocky', 'retro',
            'digital', 'virtual', 'robotic', 'cybernetic',
            'overclocked', 'turbocharged', 'supersonic',
            'frame_perfect', 'pixel_perfect', 'overpowered',
            'underpowered', 'broken', 'buffed', 'nerfed',
            'hardcore', 'casual', 'sweaty', 'salty', 'toxic',
            'cheesy', 'cheap', 'rusty', 'clutch', 'legendary',
            'epic', 'mythic', 'elite', 'invincible', 'unbeatable',
            'unstoppable', 'immortal', 'undefeated', 'victorious',
            'heroic', 'fearless', 'savage', 'brutal', 'merciless',
            'relentless', 'deadly', 'lethal', 'explosive',
            'radioactive', 'furious', 'frantic', 'reckless',
            'swift', 'nimble', 'sneaky', 'stealthy', 'tactical',
            'clumsy', 'lucky', 'secret', 'hidden', 'rare',
            'shiny', 'golden', 'unlockable',
        ],
        'heroes' => [
            'pac_man', 'blinky', 'pinky', 'inky', 'clyde',
            'donkey_kong', 'mario', 'luigi', 'qbert', 'frogger',
            'dig_dug', 'paperboy', 'bomberman', 'sonic', 'tails',
            'knuckles', 'little_mac', 'strider_hiryu', 'ryu',
            'ken', 'chun_li', 'guile', 'blanka', 'zangief',
            'dhalsim', 'bison', 'akuma', 'cammy', 'honda',
            'sagat', 'scorpion', 'sub_zero', 'raiden', 'liu_kang',
            'johnny_cage', 'kitana', 'mileena', 'sonya', 'jax',
            'shao_kahn', 'goro', 'reptile', 'terry_bogard', 'kyo',
            'iori', 'kazuya', 'heihachi', 'yoshimitsu',
            'nina_williams', 'morrigan', 'haggar', 'billy_lee',
            'abobo', 'leonardo', 'donatello', 'raphael',
            'michelangelo', 'shredder',
        ],
    ],

];
