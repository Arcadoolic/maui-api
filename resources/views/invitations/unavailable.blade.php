@extends('invitations.layout')

@section('content')
    <h1>{{ __('Invitation unavailable') }}</h1>
    @switch($reason)
        @case('expired')
            <p>{{ __('This invitation has expired.') }}</p>
            @break
        @case('claimed')
            <p>{{ __('This invitation has already been used.') }}</p>
            <p class="muted">{{ __('If you did not keep the configuration, the credentials are lost.') }}</p>
            @break
        @default
            <p>{{ __('This invitation link is not valid. Check that you copied the whole link.') }}</p>
    @endswitch
    <p>{{ __('Ask the administrator for a new link.') }}</p>
@endsection
