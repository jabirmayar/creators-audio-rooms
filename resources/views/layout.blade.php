<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(substr(app()->getLocale(), 0, 2), ['ar', 'fa', 'he', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ku'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('creators-audio-rooms.branding.name'))</title>
</head>
<body class="car-page-body" data-theme="{{ config('creators-audio-rooms.branding.theme', 'light') }}">
    <main class="car-page">
        @yield('content')
    </main>
</body>
</html>
