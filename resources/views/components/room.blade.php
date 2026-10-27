{{--
    Embeds a live audio room.

    <x-creators-audio-rooms::room :room="$room" />

    $room: a daacreators\CreatorsAudioRooms\Models\Room or its id.
    Access is checked by the API on every request, so embedding a room never
    grants access by itself.
--}}
@props(['room', 'height' => null])

@php
    $roomId = $room instanceof \daacreators\CreatorsAudioRooms\Models\Room ? $room->getKey() : (string) $room;
    $routeName = config('creators-audio-rooms.routes.name', 'audio-rooms.');
    $config = [
        'room' => $roomId,
        'api' => route($routeName.'api.show', $roomId),
        'csrf' => csrf_token(),
        'labels' => trans('creators-audio-rooms::messages.ui'),
        'branding' => config('creators-audio-rooms.branding'),
        'diagnostics' => (bool) config('creators-audio-rooms.diagnostics', true),
        'login_url' => \Illuminate\Support\Facades\Route::has((string) config('creators-audio-rooms.routes.login_route', 'login'))
            ? route(config('creators-audio-rooms.routes.login_route', 'login'))
            : null,
    ];
@endphp

<x-creators-audio-rooms::assets />

<div {{ $attributes->merge(['class' => 'car-room']) }}
     data-audio-room="{{ json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
     data-theme="{{ config('creators-audio-rooms.branding.theme', 'light') }}"
     @if($height) data-height="{{ $height }}" @endif>
    <noscript>{{ __('creators-audio-rooms::messages.ui.webrtc_missing') }}</noscript>
</div>
