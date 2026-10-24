@extends(config('creators-audio-rooms.layout.view') ?: 'creators-audio-rooms::layout')

@section('title', __('creators-audio-rooms::messages.ui.rooms'))

@section(config('creators-audio-rooms.layout.section', 'content'))
@php($ui = fn ($key, $replace = []) => __('creators-audio-rooms::messages.ui.'.$key, $replace))
@php($routes = config('creators-audio-rooms.routes.name', 'audio-rooms.'))
<x-creators-audio-rooms::assets />

<div class="car-root car-root--plain" data-theme="{{ config('creators-audio-rooms.branding.theme', 'light') }}">
    <div class="car-list-head">
        <h1 class="car-list-heading">{{ $ui('rooms') }}</h1>
    </div>

    @if($canCreate)
        <form class="car-form car-form--spaced" data-audio-rooms-create
              action="{{ route($routes.'api.store') }}" data-csrf="{{ csrf_token() }}">
            <strong>{{ $ui('create_title') }}</strong>
            <label>{{ $ui('title_label') }}
                <input name="title" required maxlength="160" autocomplete="off">
            </label>
            <label>{{ $ui('description_label') }}
                <textarea name="description" rows="2" maxlength="2000"></textarea>
            </label>
            <p class="car-form-error" data-error role="alert"></p>
            <div><button type="submit" class="car-btn car-btn--primary">{{ $ui('create') }}</button></div>
        </form>
    @endif

    {{-- Refreshed in the background from the room list API, so new and ended rooms appear without a reload. --}}
    <div data-audio-rooms-list="{{ json_encode([
            'api' => route($routes.'api.index'),
            'labels' => [
                'live' => $ui('live'),
                'scheduled' => $ui('scheduled'),
                'people_count' => $ui('people_count'),
                'yours' => $ui('yours'),
                'no_rooms' => $ui('no_rooms'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}" aria-live="polite">
        @if($rooms->isEmpty())
            <p class="car-empty">{{ $ui('no_rooms') }}</p>
        @else
            <div class="car-list">
                @foreach($rooms as $room)
                    <a class="car-list-item" href="{{ route($routes.'show', $room['id']) }}">
                        <div class="car-list-item-main">
                            <div class="car-list-item-title">{{ $room['title'] }}</div>
                            <div class="car-meta">
                                @if($room['audience_label'])<span>{{ $room['audience_label'] }}</span>@endif
                                @if($room['status'] === 'live')<span>{{ $ui('people_count', ['count' => $room['people']]) }}</span>@endif
                                @if($room['is_mine'])<span>{{ $ui('yours') }}</span>@endif
                            </div>
                        </div>
                        @if($room['status'] === 'live')
                            <span class="car-badge car-badge--live">{{ $ui('live') }}</span>
                        @else
                            <span class="car-badge">{{ $ui('scheduled') }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
