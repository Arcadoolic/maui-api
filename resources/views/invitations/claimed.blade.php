@extends('invitations.layout')

@section('content')
    <h1>{{ __('Your MAUI configuration') }}</h1>
    <p class="name-label muted">{{ __('Cabinet name') }}</p>
    <p class="name">{{ $cabinetName }}</p>
    <p>{{ __('In the MAUI back office, switch to ONLINE mode and paste this configuration in the "Paste configuration" field, then click "Test connection".') }}</p>

    <label for="configuration" class="muted">{{ __('Configuration') }}</label>
    <textarea id="configuration" rows="4" readonly>{{ $configuration }}</textarea>
    <p><button type="button" id="copy">{{ __('Copy') }}</button></p>

    <p class="notice"><strong>{{ __('Keep it somewhere safe: it will not be shown again.') }}</strong>
        {{ __('If you lose it, ask the administrator for a new link.') }}</p>

    <script nonce="{{ Vite::cspNonce() }}">
        document.getElementById('copy').addEventListener('click', async (event) => {
            const field = document.getElementById('configuration');
            try {
                await navigator.clipboard.writeText(field.value);
            } catch {
                field.select();
                document.execCommand('copy');
            }
            event.target.textContent = @json(__('Copied'));
        });
    </script>
@endsection
