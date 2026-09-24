@extends('invitations.layout')

@section('content')
    <h1>{{ __('Your MAUI credentials') }}</h1>
    <p>{{ __('This link gives access to the ONLINE mode of your cabinet.') }}</p>

    <p class="name-label muted">{{ __('Cabinet name') }}</p>
    <p class="name">{{ $cabinetName }}</p>

    @if ($renameUrl)
        <form method="POST" action="{{ $renameUrl }}">
            @csrf
            <button type="submit" class="secondary">{{ __('Another name') }}</button>
        </form>
        <p class="muted">{{ __('Draw as many names as you like. The name is final once the credentials are retrieved.') }}</p>
    @endif

    <p class="muted">{{ __('The credentials are displayed only once. Open this page on a device where you can copy them to the MAUI back office.') }}</p>

    <form method="POST" action="{{ $claimUrl }}">
        @csrf
        <button type="submit">{{ __('Get my credentials') }}</button>
    </form>
@endsection
