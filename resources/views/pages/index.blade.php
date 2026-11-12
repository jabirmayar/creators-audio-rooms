@extends(config('creators-audio-rooms.layout.view') ?: 'creators-audio-rooms::layout')

@section('title', __('creators-audio-rooms::messages.ui.rooms'))

@section(config('creators-audio-rooms.layout.section', 'content'))
@php($ui = fn ($key, $replace = []) => __('creators-audio-rooms::messages.ui.'.$key, $replace))
@php($routes = config('creators-audio-rooms.routes.name', 'audio-rooms.'))
<x-creators-audio-rooms::assets />

<div class="car-root car-root--plain car-lobby" data-theme="{{ config('creators-audio-rooms.branding.theme', 'light') }}">
    <div class="car-lobby-head">
        <div class="car-lobby-heading">
            <h1 class="car-list-heading">{{ $ui('rooms') }}</h1>
            <p class="car-lobby-subtitle">{{ $ui('rooms_subtitle') }}</p>
        </div>

        @if($canCreate)
            @php($choices = $audienceChoices ?? ['enabled' => false, 'everyone' => true, 'options' => [], 'search' => false])
            @php($canSearch = $choices['enabled'] && ! empty($choices['search']))
            @php($cannotStart = $choices['enabled'] && empty($choices['options']) && ! $choices['everyone'] && ! $canSearch)
            {{-- A dialog: header, a body that scrolls, and a footer whose "Go live" button is always in view. --}}
            <details class="car-create">
                <summary class="car-btn car-btn--primary">{{ $ui('create_title') }}</summary>
                <form class="car-form car-create-form" data-audio-rooms-create role="dialog" aria-modal="true" aria-labelledby="car-create-title"
                      action="{{ route($routes.'api.store') }}" data-csrf="{{ csrf_token() }}">
                    <div class="car-create-head">
                        <h2 id="car-create-title">{{ $ui('create_title') }}</h2>
                        <button type="button" class="car-btn car-btn--small car-create-close" data-create-close aria-label="{{ $ui('close') }}">×</button>
                    </div>
                    <div class="car-create-body">
                        <label>{{ $ui('title_label') }}
                            <input name="title" required maxlength="160" autocomplete="off">
                        </label>
                        <label>{{ $ui('description_label') }}
                            <textarea name="description" rows="2" maxlength="2000"></textarea>
                        </label>

                        @if($choices['enabled'] && ! $cannotStart)
                            <fieldset class="car-audience" data-audience-picker data-label-selected="{{ $ui('audience_selected') }}">
                                <legend>{{ $ui('who_can_join') }}</legend>
                                @if($choices['everyone'])
                                    <label class="car-check car-check--everyone">
                                        <input type="checkbox" name="everyone" value="1" checked data-audience-everyone>
                                        <span><strong>{{ $ui('everyone') }}</strong><small>{{ $ui('everyone_hint') }}</small></span>
                                    </label>
                                @endif
                                @if($canSearch)
                                    {{-- People looked up on the server as the host types; chosen ones stay listed. --}}
                                    <div class="car-audience-remote" data-audience-remote="{{ route($routes.'api.audiences') }}"
                                         data-label-searching="{{ $ui('audience_searching') }}" data-label-no-results="{{ $ui('audience_no_results') }}" data-label-hint="{{ $ui('audience_search_hint') }}">
                                        <input type="search" class="car-audience-search" placeholder="{{ $ui('audience_search_remote') }}" aria-label="{{ $ui('audience_search_remote') }}" autocomplete="off" data-audience-remote-input>
                                        <div class="car-audience-group" data-audience-remote-picked hidden>
                                            <p class="car-audience-group-title">{{ $ui('audience_selected_group') }}</p>
                                            <div class="car-audience-group" data-audience-remote-picked-list></div>
                                        </div>
                                        <div class="car-audience-group" data-audience-remote-results hidden>
                                            <p class="car-audience-group-title">{{ $ui('audience_results') }}</p>
                                            <div class="car-audience-group" data-audience-remote-results-list></div>
                                            <p class="car-audience-count" data-audience-remote-status></p>
                                        </div>
                                    </div>
                                @endif
                                @if(count($choices['options']) > 8)
                                    <input type="search" class="car-audience-search" placeholder="{{ $ui('audience_search') }}" aria-label="{{ $ui('audience_search') }}" data-audience-search>
                                @endif
                                <div class="car-audience-list" data-audience-list>
                                    @foreach(collect($choices['options'])->groupBy(fn ($o) => $o['group'] ?? '') as $group => $items)
                                        <div class="car-audience-group" data-audience-group>
                                            @if($group !== '')<p class="car-audience-group-title">{{ $group }}</p>@endif
                                            @foreach($items as $option)
                                                <label class="car-check" data-audience-item data-search="{{ \Illuminate\Support\Str::lower($option['label'].' '.($option['group'] ?? '')) }}">
                                                    <input type="checkbox" name="audiences[]" value="{{ $option['key'] }}">
                                                    <span>{{ $option['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                                <p class="car-audience-count" data-audience-count aria-live="polite"></p>
                            </fieldset>
                        @endif

                        <p class="car-form-error" data-error role="alert">@if($cannotStart){{ $ui('no_audiences') }}@endif</p>
                    </div>
                    <div class="car-create-foot">
                        <button type="button" class="car-btn" data-create-close>{{ $ui('cancel') }}</button>
                        <button type="submit" class="car-btn car-btn--primary" @disabled($cannotStart)>{{ $ui('create') }}</button>
                    </div>
                </form>
            </details>
        @endif
    </div>

    {{-- Drawn and kept up to date by the browser bundle from these rooms and the room list API. --}}
    <div data-audio-rooms-list="{{ json_encode([
            'api' => route($routes.'api.index'),
            'rooms' => $rooms->map(fn ($room) => $room + ['url' => route($routes.'show', $room['id'])])->values(),
            'labels' => collect(['live', 'scheduled', 'people_count', 'yours', 'no_rooms', 'no_rooms_hint', 'hosted_by', 'live_for',
                'started_just_now', 'duration_minutes', 'duration_hours', 'join_short', 'open_room', 'view_room', 'for_audience'])
                ->mapWithKeys(fn ($key) => [$key => $ui($key)]),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}" aria-live="polite">
        <noscript>
            <ul>
                @forelse($rooms as $room)
                    <li><a href="{{ route($routes.'show', $room['id']) }}">{{ $room['title'] }}</a></li>
                @empty
                    <li>{{ $ui('no_rooms') }}</li>
                @endforelse
            </ul>
        </noscript>
    </div>
</div>
@endsection
