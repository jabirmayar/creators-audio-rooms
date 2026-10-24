{{-- Stylesheet and script for audio rooms. Included once per page by the room component and pages. --}}
@once
    @php
        $base = 'vendor/creators-audio-rooms/';
        $version = fn (string $file) => is_file(public_path($base.$file)) ? filemtime(public_path($base.$file)) : null;
    @endphp
    <link rel="stylesheet" href="{{ asset($base.'audio-rooms.css') }}{{ $version('audio-rooms.css') ? '?v='.$version('audio-rooms.css') : '' }}">
    <script src="{{ asset($base.'audio-rooms.js') }}{{ $version('audio-rooms.js') ? '?v='.$version('audio-rooms.js') : '' }}" defer></script>
@endonce
