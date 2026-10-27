<?php

use daacreators\CreatorsAudioRooms\Integration\DefaultAuthorizer;
use daacreators\CreatorsAudioRooms\Integration\GuardParticipantResolver;
use daacreators\CreatorsAudioRooms\Integration\NoTenantResolver;
use daacreators\CreatorsAudioRooms\Integration\NullNotifier;
use daacreators\CreatorsAudioRooms\Integration\OpenAudienceResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Package tables are created with this prefix and never reference the host
    | application's user tables. Change it before running the migrations.
    |
    */

    'table_prefix' => env('AUDIO_ROOMS_TABLE_PREFIX', 'audio_'),

    'database_connection' => env('AUDIO_ROOMS_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Host integration
    |--------------------------------------------------------------------------
    |
    | Each class implements a contract in daacreators\CreatorsAudioRooms\Contracts.
    | The defaults work for a typical app with one `users` table; replace them to
    | map your own users, guards, permissions, audiences and tenants.
    |
    */

    'integration' => [
        'participant_resolver' => GuardParticipantResolver::class,
        'authorizer' => DefaultAuthorizer::class,
        'audience_resolver' => OpenAudienceResolver::class,
        'tenant_resolver' => NoTenantResolver::class,
        'notifier' => NullNotifier::class,
    ],

    /*
    | Used by the default GuardParticipantResolver. Each guard gets its own
    | participant type so IDs from different tables never collide
    | (e.g. "user:5" and "admin:5" are different people).
    */
    'guards' => [
        'web' => [
            'type' => 'user',
            'name_attribute' => 'name',
            'avatar_attribute' => null,
            'profile_route' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Room limits
    |--------------------------------------------------------------------------
    |
    | Audio is peer-to-peer: every speaker sends a separate stream to every
    | listener, so rooms must stay small. Speakers include hosts.
    |
    */

    'limits' => [
        'max_hosts' => 2,
        'max_speakers' => 6,
        'max_listeners' => 30,
        'max_open_rooms_per_host' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    'lifecycle' => [
        // A member with no heartbeat for this long is treated as disconnected.
        'presence_timeout_seconds' => 25,
        // Pages in the background (phone locked, tab hidden) check in rarely; give them longer.
        'presence_hidden_timeout_seconds' => 150,
        // With no host connected, the room ends after this grace period.
        'host_absent_grace_seconds' => 120,
        // Rooms that never started are removed after this many hours.
        'unstarted_room_ttl_hours' => 24,
        // Ended rooms (and their members) are pruned after this many days.
        'ended_room_retention_days' => 30,
        // Register creators-audio-rooms:prune with the Laravel scheduler (every five minutes).
        'schedule_prune' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signaling
    |--------------------------------------------------------------------------
    |
    | Connection setup and room updates travel over ordinary HTTP requests to
    | this app ("sync"). Transient messages live in the cache with a short TTL
    | and are never written to the database. Audio never passes through PHP.
    |
    */

    'signaling' => [
        'cache_store' => env('AUDIO_ROOMS_CACHE_STORE'),
        'sync_interval_ms' => 1200,
        'sync_interval_idle_ms' => 2500,
        'sync_interval_hidden_ms' => 5000,
        'message_ttl_seconds' => 60,
        'max_messages_per_sync' => 50,
        // Signed session tokens for the sync/signal endpoints.
        'session_token_ttl_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | WebRTC
    |--------------------------------------------------------------------------
    |
    | Public STUN servers help browsers find a direct route to each other.
    | Strict networks (some offices, mobile carriers) also need a TURN relay;
    | configure one under 'turn' below.
    |
    */

    'ice_servers' => [
        ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']],
        ['urls' => ['stun:stun.cloudflare.com:3478']],
    ],

    /*
    | Optional TURN relay for networks that block direct connections. Browsers
    | receive short-lived credentials derived from the shared secret (coturn
    | "use-auth-secret" / "static-auth-secret"); the secret never leaves the server.
    | Example urls: ['turn:turn.example.com:3478', 'turns:turn.example.com:5349'].
    */
    'turn' => [
        'urls' => array_filter(explode(',', (string) env('AUDIO_ROOMS_TURN_URLS', ''))),
        // Either a shared secret (coturn "use-auth-secret": short-lived credentials are generated per join)…
        'secret' => env('AUDIO_ROOMS_TURN_SECRET'),
        'ttl_seconds' => 3600,
        // …or a fixed username and credential, as most hosted TURN providers issue.
        'username' => env('AUDIO_ROOMS_TURN_USERNAME'),
        'credential' => env('AUDIO_ROOMS_TURN_CREDENTIAL'),

        // …or Cloudflare Realtime TURN: set the TURN key id and its API token. Short-lived
        // credentials are requested from Cloudflare and cached; the token never reaches browsers.
        'cloudflare' => [
            'key_id' => env('AUDIO_ROOMS_CLOUDFLARE_TURN_KEY_ID'),
            'api_token' => env('AUDIO_ROOMS_CLOUDFLARE_TURN_API_TOKEN'),
            'ttl_seconds' => 86400,
        ],
    ],

    /*
    | 'all' lets browsers connect directly when they can and fall back to the
    | relay; 'relay' forces every connection through the TURN relay (hides
    | participants' IP addresses from each other; needs a relay).
    */
    'ice_transport_policy' => env('AUDIO_ROOMS_ICE_POLICY', 'all'),

    /*
    | Audio sent by each speaker. A cap keeps uploads small when one speaker
    | sends to many listeners (32 kbps Opus is clear for speech).
    */
    'audio' => [
        'max_bitrate' => 32000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        // Ready-made room list and room pages. Turn off to embed the Blade component in your own pages only.
        'pages' => true,
        'prefix' => 'audio-rooms',
        'name' => 'audio-rooms.',
        'middleware' => ['web'],
        // Sync and signal requests (every second or two per participant) are authenticated by the signed
        // room session token and skip the app session and cookies. Add middleware here if they need any.
        'signaling_middleware' => [],
        // Where signed-out visitors are sent from the pages (route name), if it exists.
        'login_route' => 'login',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | The ready-made pages render inside this Blade layout. Leave null for the
    | package's own minimal layout, or set your app layout (e.g. "layouts.app")
    | and the section the content goes into.
    |
    */

    'layout' => [
        'view' => null,
        'section' => 'content',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (per participant, per minute)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'sync' => 120,
        'signal' => 240,
        'actions' => 60,
        'create' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Colours become CSS variables on the room UI. Publish the views to change
    | markup without editing vendor files.
    |
    */

    'branding' => [
        'name' => 'Audio rooms',
        'accent' => '#059669',
        'accent_contrast' => '#ffffff',
        'radius' => '14px',
        // 'light', 'dark', or 'auto' to follow the visitor's system setting.
        'theme' => 'light',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    |
    | Rooms are not recorded. Audio travels only between participants' browsers
    | and is never stored by this package.
    |
    */

    'recording' => [
        'enabled' => false,
    ],
];
