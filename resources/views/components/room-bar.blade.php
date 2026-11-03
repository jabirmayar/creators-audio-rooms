{{--
    "You are in a room" bar for your app's other pages.

    <x-creators-audio-rooms::room-bar />

    Put it once in your layout (e.g. before </body>). While a room is open in another tab of the same
    browser, a small bar shows its title, a microphone button and "Back to room". It does nothing on
    the room page itself and sends nothing to the server.
--}}
@once
    @php
        $base = 'vendor/creators-audio-rooms/';
        $version = fn (string $file) => is_file(public_path($base.$file)) ? filemtime(public_path($base.$file)) : null;
        $labels = [
            'label' => __('creators-audio-rooms::messages.ui.bar_label'),
            'in_room' => __('creators-audio-rooms::messages.ui.bar_in_room'),
            'back' => __('creators-audio-rooms::messages.ui.bar_back'),
            'mic_on' => __('creators-audio-rooms::messages.ui.mic_on'),
            'mic_off' => __('creators-audio-rooms::messages.ui.mic_off'),
        ];
    @endphp
    <link rel="stylesheet" href="{{ asset($base.'audio-rooms-bar.css') }}{{ $version('audio-rooms-bar.css') ? '?v='.$version('audio-rooms-bar.css') : '' }}">
    <script src="{{ asset($base.'audio-rooms-bar.js') }}{{ $version('audio-rooms-bar.js') ? '?v='.$version('audio-rooms-bar.js') : '' }}" defer></script>
    <div data-audio-rooms-bar="{{ json_encode($labels, JSON_UNESCAPED_UNICODE) }}" hidden></div>
@endonce
