{{--
    A "Live now" strip of rooms the signed-in visitor can join, for a home page
    or dashboard. Hidden while nothing is live; refreshes itself.

    <x-creators-audio-rooms::live-now />
    <x-creators-audio-rooms::live-now :limit="4" />
--}}
@props(['limit' => 6])

@php
    $routeName = config('creators-audio-rooms.routes.name', 'audio-rooms.');
    $who = config('creators-audio-rooms.routes.enabled', true)
        ? app(\daacreators\CreatorsAudioRooms\Contracts\ParticipantResolver::class)->resolve(request())
        : null;
    $hasPages = \Illuminate\Support\Facades\Route::has($routeName.'show');
    $rooms = $who
        ? collect(app(\daacreators\CreatorsAudioRooms\Services\RoomStatePresenter::class)->directory($who))
            ->where('status', 'live')
            ->map(fn ($room) => $room + ['url' => $hasPages ? route($routeName.'show', $room['id']) : null])
            ->values()
        : collect();
    $ui = fn ($key) => __('creators-audio-rooms::messages.ui.'.$key);
@endphp

@if($who)
    <x-creators-audio-rooms::assets />
    <div {{ $attributes->merge(['class' => 'car-root car-root--plain car-live-now']) }}
         data-theme="{{ config('creators-audio-rooms.branding.theme', 'light') }}"
         data-audio-rooms-live="{{ json_encode([
            'api' => route($routeName.'api.index'),
            'limit' => (int) $limit,
            'all_url' => \Illuminate\Support\Facades\Route::has($routeName.'index') ? route($routeName.'index') : null,
            'rooms' => $rooms,
            'labels' => collect(['live_now', 'listen', 'people_count', 'hosted_by', 'see_all'])->mapWithKeys(fn ($key) => [$key => $ui($key)]),
         ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}"
         @if($rooms->isEmpty()) hidden @endif></div>
@endif
