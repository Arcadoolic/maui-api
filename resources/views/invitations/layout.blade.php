<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('MAUI credentials') }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        :root { color-scheme: light dark; --accent: #e8413b; --muted: #6b7280; --panel: #f4f4f5; }
        @media (prefers-color-scheme: dark) { :root { --muted: #a1a1aa; --panel: #27272a; } }
        body { font-family: system-ui, sans-serif; line-height: 1.5; margin: 0; padding: 2rem 1rem; }
        main { max-width: 36rem; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin-top: 0; }
        p { margin: 0 0 1rem; }
        .muted { color: var(--muted); }
        .notice { background: var(--panel); border-left: 4px solid var(--accent); padding: .75rem 1rem; margin: 1rem 0; }
        button { background: var(--accent); border: 0; border-radius: .375rem; color: #fff; cursor: pointer; font-size: 1rem; padding: .625rem 1.25rem; }
        button.secondary { background: transparent; border: 2px solid var(--accent); color: var(--accent); }
        form { margin: 0 0 1rem; }
        .name-label { font-size: .875rem; margin: 0; }
        .name { font-family: ui-monospace, monospace; font-size: 1.5rem; font-weight: 700; margin: 0 0 1rem; }
        textarea { box-sizing: border-box; font-family: ui-monospace, monospace; font-size: .875rem; padding: .5rem; width: 100%; word-break: break-all; }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
