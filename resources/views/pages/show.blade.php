@extends(config('creators-audio-rooms.layout.view') ?: 'creators-audio-rooms::layout')

@section('title', $room->title)

@section(config('creators-audio-rooms.layout.section', 'content'))
@if(config('creators-audio-rooms.routes.pages', true))
    <x-creators-audio-rooms::assets />
    <a class="car-back-link" href="{{ route(config('creators-audio-rooms.routes.name', 'audio-rooms.').'index') }}">
        ← {{ __('creators-audio-rooms::messages.ui.rooms') }}
    </a>
@endif
<x-creators-audio-rooms::room :room="$room" />
@endsection
