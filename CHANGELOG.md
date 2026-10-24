# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## 1.4.0 - 2026-10-24

### Added

- `<x-creators-audio-rooms::live-now />`: a "Live now" strip of joinable live rooms for home pages and dashboards; hides when nothing is live.
- `POST /api/rooms` accepts `start: true` to create a room that is already live.

### Changed

- **Go live** (the room list form) creates and starts the room and puts the creator straight in as host, instead of showing "Enter as host" and "Start room".
- The open-room limit counts rooms the person currently hosts, so handing a room to a co-host (by leaving) frees them to start another.
- Phones: the create form opens in place, request rows wrap instead of truncating names, the control bar stays on one line (End and Leave as icons), and member menus open upward near the bottom of the screen.

## 1.3.0 - 2026-10-24

### Added

- Monitoring: participants the app allows (`RoomAction::Monitor`, never granted by default; e.g. an `audio-rooms.monitor` gate) can **Join as monitor**. Monitors get in even when the room is full, are not blocked by a room-level removal or ban, hear the room without speaking, and can moderate everyone including the host. Hosts cannot act on monitors.
- Monitoring is always visible: rooms show "Monitored by …", and the room state includes `monitors`, `counts.monitors` and `can.monitor`.
- `POST /api/rooms/{room}/join` accepts `as=monitor`.
- When every listener place is taken, the join button is disabled with an explanation instead of failing after a click.

### Changed

- `RoomRole::canSpeak()` now lists the speaking roles explicitly (host, co-host, speaker).

## 1.2.0 - 2026-10-24

### Changed

- Redesigned room list: cards with the host, the faces of who is speaking, how long the room has been live, the number of people and a Join button. "Start a room" opens the form on demand, and an empty list shows a proper empty state.
- Redesigned room: one speaker area for hosts and speakers with larger faces and Host/Co-host labels, a compact listener grid, "Hosted by · Live for · in the room" in the header, a clearer join bar, and no fixed height (small rooms no longer leave empty space). A loading placeholder shows until the room is ready.
- New icon set (inline SVG): solid status badges on faces, a waving hand for raised hands, animated sound bars while someone speaks, a pulsing invitation badge, crown and star for hosts and co-hosts, and icons in section titles, notices and buttons. All motion respects reduced-motion settings.
- People without a photo get one of six soft colours instead of the same tint.

### Added

- The room list API and pages include each room's host, current speakers (`stage`, `stage_count`) and the room state includes `host`.

## 1.1.2 - 2026-10-24

### Fixed

- Expected refusals (not allowed, room full, session expired and similar) are no longer written to the app's error log.

## 1.1.1 - 2026-10-24

### Fixed

- `creators-audio-rooms:install --force` no longer overwrites an existing `config/creators-audio-rooms.php`. The install command always refreshes the browser assets and keeps your config; use `--reset-config` to replace it with the package default.

## 1.1.0 - 2026-10-24

### Changed

- Check-in (`sync`) and connection-message (`signal`) requests are authenticated by the signed room session token alone and no longer start or write the app session. This removes lost-session races (people being signed out, "CSRF token mismatch") with session drivers that have no locking, such as `file` and `cookie`, and makes check-ins cheaper.
- Room session tokens are renewed through the new `GET /api/rooms/{room}/session` endpoint, which uses the app session, so signing out of the app still disconnects the person from the room.
- Room lists and the room header show how many people are in the room (`:count in the room`); the list API returns `people` alongside `listeners`, and people who closed the page without leaving are no longer counted once they time out.

### Added

- `routes.signaling_middleware` config option for middleware on the sync and signal routes.

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

