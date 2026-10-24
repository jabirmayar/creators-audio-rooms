# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-10-24

First public release. Supports Laravel 12 (PHP 8.2+) and Laravel 13 (PHP 8.3+).

### Rooms

- Live audio rooms with hosts, co-hosts, speakers and listeners; limits of 2 hosts, 6 voices (hosts included) and 30 listeners by default.
- Rooms start as *not started*; hosts enter, prepare and start them. Rooms without a host end after a grace period.
- Ready-made room list and room pages, a `<x-creators-audio-rooms::room>` Blade component for embedding, and a JSON API.
- Room list refreshes in the background and is available as `GET /audio-rooms/api/rooms`.

### Speaking and moderation

- Hand raising with an ordered request queue for moderators, a request counter in the control bar and an alert for new requests.
- Invitations to speak that the listener accepts or declines; hosts can withdraw them. Nobody becomes a speaker without agreeing.
- Mute (cannot be undone remotely), allow to unmute, move to listeners, make co-host, remove and ban (room or tenant wide), with a moderation log.
- Host handover to the longest-present co-host.

### Audio and connectivity

- Peer-to-peer WebRTC audio with signaling over ordinary HTTP requests to the host app; nothing extra to host.
- Optional TURN relay with short-lived per-participant credentials (shared-secret scheme).
- Audio capped per connection (32 kbps by default) and ICE candidates sent in batches.
- Reconnects after network loss, reloads and expired sessions; the newest tab or device takes over.
- Adaptive check-in interval (faster while active, slower when idle or hidden).

### Integration

- Contracts for participants, authorization, audiences, tenants and notifications, with defaults that work for a typical app.
- Several user tables or guards, integer or string IDs, and tenant isolation.
- Events for every room change, including `SpeakerInvited`.
- `install`, `doctor`, `prune` (registered with the scheduler) and `forget` commands.

### Interface

- Dependency-free browser bundle and stylesheet, no build step.
- Light, dark and automatic themes; accent colour and radius from config.
- Keyboard-operable menus, accessible confirmation dialog, screen-reader announcements and right-to-left layout.
- Works under a strict Content-Security-Policy (no inline scripts or styles).

