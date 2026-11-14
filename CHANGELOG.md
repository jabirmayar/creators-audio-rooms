# Changelog

## 1.15.1 - 2026-11-14

### Fixed

- Cloudflare SFU subscription failures now log bounded, sanitized per-track error details, making failed speaker subscriptions diagnosable without logging session identifiers or SDP.

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## 1.15.0 - 2026-11-12

### Added

- "Start a room" is now a dialog with a header, a scrolling body and a footer, so the Go live button is always on screen; on phones it is a bottom sheet. It closes from Close, Cancel, Escape or a tap outside.
- Searchable audiences: an audience resolver may also implement `SearchesAudienceOptions` (`searchOptions`, `optionFor`). The start-room form then shows a search box; `GET /api/audiences?q=` returns matches and a chosen key that is not among the listed options is accepted when `optionFor()` vouches for it. Lets hosts pick single people out of thousands.
- Participant details: each guard may name a `details_attribute` (an attribute or accessor on the user model) holding a short line about the person, e.g. "Grade 6" or "Teacher". It is saved on the member when they join and shown under their name, but only to viewers whose participant type is listed in `details_visible_to_types` (default `['staff']`), whatever their role in the room. A student made host or co-host never receives it. Migration adds `details` to the members table.

## 1.14.1 - 2026-11-12

### Fixed

- Room model docblock lists the new time-limit columns, so static analysis passes.

## 1.14.0 - 2026-11-12

### Added

- Room time limit: `lifecycle.max_room_minutes` (env `AUDIO_ROOMS_MAX_ROOM_MINUTES`, default 0 = no limit). A room started under a limit carries `ends_at`; everyone sees "Ends in …" in the header, hosts get a notice five minutes before the end and everyone one minute before. The room ends on the first check-in or list after the deadline (`end_reason` = `time_limit`), so no scheduler is needed; the prune command catches rooms nobody is polling.
- Hosts and co-hosts may extend a room once by the same number of minutes (`POST /api/rooms/{room}/extend`, `can.extend` in the state); `lifecycle.allow_extension` turns it off. Extensions are written to the moderation log.

### Changed

- Who a room is for (`audience_label`, `audiences`) is now only sent to the room's host, co-hosts and creator. Listeners and the room list seen by ordinary members no longer name the chosen audience, which could identify a single person a room was opened for.
- Errors shown to participants: a check-in that fails once no longer flashes "Connection lost"; the banner waits for a second miss or six seconds without an answer (the audio itself keeps running either way). Server faults and dropped requests show one calm line instead of the server's own text, safe-to-repeat actions (join, start, end, hand, mute, invitation, moderation) are retried once after 1.2 s before anything is shown, and error banners fade after ten seconds.

## 1.13.0 - 2026-11-10

### Changed

- SFU transport: pages check in at one flat pace (`signaling.sync_interval_sfu_ms`, default 2500 ms) instead of the peer-to-peer rhythm. Offers and candidates never travel over check-ins in SFU mode, so the 300 ms linking pace and the speakers' 1.2 s pace only cost the server requests; a room of thirty now makes a few requests a second instead of dozens.
- SFU transport: the speaker's upload is capped like direct links (`audio.max_bitrate`, 32 kbps by default), so phones on weak uplinks send only what speech needs.
- SFU transport: dropped links come back sooner. A link that stays "disconnected" is rebuilt after 3 s (was 6 s) and a failed one at once, with a pause that doubles up to 8 s while links keep dropping; when the browser reports the network is back, dropped links are rebuilt straight away.

## 1.12.10 - 2026-11-10

### Changed

- Cloudflare Realtime session creation sends no request body, as required by `POST /sessions/new`. Failed microphone publishing retries with exponential backoff instead of repeating on every room sync.
- Cloudflare Realtime SFU request failures log the operation, response description, and Cloudflare Ray ID when available, so decoding and validation errors can be diagnosed without logging SDP or credentials.

## 1.12.0 - 2026-11-04

### Changed

- A slow or briefly failing server no longer makes a room rebuild its audio links. A page whose room session lapsed, or which the server had meanwhile treated as gone, rejoins with its old token and keeps its connection id (`POST /join` reads the `X-Audio-Room-Session` header, expired or not, when it is signed for that person and room); the others see nothing change. A connection another tab has since taken over is never handed back. The rejoining page keeps its links and its place in the message stream.
- `lifecycle.presence_timeout_seconds` defaults to 60 (was 25): a member counts as gone only after a minute without a successful check-in. Hidden pages keep their 150 s.
- The 300 ms linking pace is used only while check-ins succeed and answer within a second; against a slow server the page falls back to the normal pace instead of piling up requests.
- A check-in that hangs is given up after 15 s and counted as a failure, so one stuck request no longer blocks the heartbeat.
- `GuardParticipantResolver::find()` returns the account already signed in on the guard when it is the same person, which saves a users query on every check-in and signal for apps whose signaling routes carry the app session.
- Each quiet rejoin is logged to the console with the server's answer and the state of recent check-ins; the diagnostics panel shows the rejoin count and the last check-in time.

## 1.11.0 - 2026-11-04

### Changed

- Links come up sooner. While a link is being set up, and while a newcomer waits for the speakers' offers, the page checks in every 300 ms (`signaling.sync_interval_linking_ms`) instead of every 1.2-2.5 s, so the offer, answer and candidates no longer each wait a full interval. Speakers keep the active pace even when idle, since they are the ones who offer links to people who join. A signal's reply (`POST /signal` with `since`) carries the messages already waiting for the sender, which saves a check-in on every answer. The default `rate_limits.sync` is 300 a minute (was 120) to leave room for the quick pace; the room state has no new fields.

## 1.10.0 - 2026-11-03

### Changed

- `max_speakers` counts speakers only. The host and co-hosts are on stage on top of it (they count against `max_hosts`), so with the defaults a room has up to 6 speakers besides its hosts. Making someone co-host no longer needs a free speaker place. The room state has a new `counts.speakers`; `counts.voices` (everyone on stage) is unchanged. `creators-audio-rooms:doctor` no longer asks for `max_speakers >= max_hosts`.

### Fixed

- A server that is briefly unavailable (503 while restarting or overloaded, other 5xx, 429 or a network error) no longer breaks audio links. Connection setup messages to each person are sent in order and retried a few times (about 6 seconds in all) instead of being dropped, which left a half-made link until the watchdog rebuilt it. A check-in that gets a 5xx now waits and retries like a network blip ("Reconnecting") instead of being treated as an error.

## 1.9.0 - 2026-11-03

### Added

- **Mini player**: in Chrome and Edge on desktop, a button opens the room in a small always-on-top window (Document Picture-in-Picture): speakers with Host / Co-host / Speaker labels and speaking rings, listeners in their own row, the microphone or raise-hand button, "Back to full room" and Leave (tap twice). Connection problems show there too. Not offered on phones and tablets (no browser there has such a window; desktop browsers emulating a phone are recognised too); if the window cannot open, the room says so. Speaking rings and check-ins keep their pace while it is open.
- While someone is in a room, links to the site's other pages open in a new tab, so the room and its mini player keep playing (`links_in_new_tab`, default on). Links the page handles itself, other sites, downloads and Ctrl/Cmd-clicks are left alone.
- **Media controls**: room title and host on the lock screen, phone notification and browser media controls; the browser's microphone and hang-up buttons work where offered (`togglemicrophone`, `hangup`). Cleared on leaving.
- **Room bar** for other pages: `<x-creators-audio-rooms::room-bar />` (and `audio-rooms-bar.js` / `audio-rooms-bar.css`). While a room is open in another tab of the same browser it shows the room, a microphone button and "Back to room", which continues the room in that tab (`?continue=`, honoured only when a tab of this browser confirms it is in the room). The microphone button is there for every speaker: if the room has not opened the microphone yet, the bar asks for permission in its own tab and the room switches it on (never a prompt in the background tab; when that is not possible the bar says what to do). Light on every page: one small deferred script; the stylesheet loads only when a bar is shown. Looks like the room (white card, the app's accent from config `branding`, the page's font). In a tab opened from the room, "Back to room" brings the room tab forward where the browser allows it. Included in the package's own page layout.
- When a newer tab of the same browser joins a room, the older one steps aside at once instead of on its next check-in. If that newer tab is then closed (or discarded, e.g. by a phone) without leaving, the older tab takes the room back by itself (microphone off); not after Leave there, not when the other tab was only reloading, and never for a takeover from another device.
- Speaking: the mini player's speaking ring pulses, and the room bar shows "… is speaking" / "You are speaking" with a pulsing icon. Both hold through the short pauses between words (background tabs, whose timers run about once a second, hold longer); muting yourself ends "You are speaking" at once. A hidden room tab keeps measuring voices (and checks in at the normal pace, so a bar notices the room ending within seconds) only while the mini player or a bar is on screen.

### Fixed

- Your own speaking ring often stayed off: it relied on the page's audio meter, which browsers keep paused until there is a click on that page ("Go live" joins by itself; the bar can switch the microphone on). Your voice is now also read from the connections' own statistics.

## 1.8.5 - 2026-11-03

### Changed

- The microphone button reacts at once. Turning the microphone on or off takes effect on the device straight away (others hear you without waiting for the server) and the server is told in the background, one request at a time so quick taps end in the right state. An unmute the server refuses (for example after a moderator mute) turns the microphone back off; a mute that does not reach the server is retried.

## 1.8.4 - 2026-11-03

### Fixed

- With `ice_transport_policy` set to `relay`, a join that got no relay server (for example while Cloudflare is unreachable) left people unable to connect to anyone. Relay-only now applies only when a relay server is handed out; otherwise the room connects directly as with `all`.
- With `ice_transport_policy` set to `relay`, a relay that could not be reached (blocked network, relay outage) meant the link never formed. Relay-only now applies to the first try only; when it fails, later tries may also connect directly (the relay stays available to them).

## 1.8.3 - 2026-11-02

### Fixed

- "Invite as co-host" was offered for people the app does not let host, and clicking it failed with "This person cannot be a host." It is now only offered for people who may host. Room state for moderators includes `can_host` on each member (cached for five minutes per room and person); the server check on inviting and accepting is unchanged.

## 1.8.2 - 2026-11-02

### Fixed

- Reloading the room page asked people to join again, even though they were in the room a moment before. A reload of the same tab now goes straight back in, in the same way (listener, speaker, host or monitor; always with the microphone off, as every re-join is). A new tab or window still asks, and so does a tab reopened more than 10 minutes later. Leaving, being removed or banned, the room ending, opening the room in another tab or signing out stops it.

## 1.8.1 - 2026-10-28

### Changed

- Speakers with their microphone off no longer send anything. Before, a switched-off microphone still sent silence to every member, which in a full room was more than half of all audio traffic (and of TURN relay usage). Turning the microphone on resumes sending at once.

### Fixed

- Profile photos that are not square (for example portrait phone photos) showed as an oval and covered the name below. Photos are now always cropped to the circle.

## 1.8.0 - 2026-10-27

### Changed

- "Make co-host" now sends an invitation (shown as "Invite as co-host"). The person becomes co-host only when they accept; until then their role does not change. The host can withdraw the invitation, and the host limit is checked again on accepting.
- A co-host cannot end the room while the host is in it (`host_present`). When the host leaves, the co-host takes over as before and can then end it. Monitors can still end any room.
- Passing messages such as "You are now the host" hide after a few seconds; they stay while pointed at or focused. Errors, messages with a button and room-state messages still stay until dismissed.

### Added

- `CoHostInvited` event, and `invited_role` (`speaker` or `co_host`) on members and on `me` in the room state.
- Migration adding `invited_role` to the members table. Run `php artisan migrate` after updating.

### Fixed

- `RoleChanged` after accepting an invitation now carries who sent the invitation in `byKey`.

## 1.7.2 - 2026-10-27

### Fixed

- Older browsers (Chrome before 87, older Android WebViews): fallbacks for `inset` and logical position properties, so person menus open, confirmation dialogs cover the screen, room cards are clickable and badges sit in place; hover styles no longer depend on `:focus-visible` support; checked "Who can join" options are highlighted without `:has()`.

## 1.7.1 - 2026-10-27

### Added

- `diagnostics` config option (`AUDIO_ROOMS_DIAGNOSTICS=false`) hides the connection details panel, its button and the browser console messages.

## 1.7.0 - 2026-10-27

### Added

- Optional Cloudflare Realtime TURN relay: set `AUDIO_ROOMS_CLOUDFLARE_TURN_KEY_ID` and `AUDIO_ROOMS_CLOUDFLARE_TURN_API_TOKEN`. Short-lived credentials are requested from Cloudflare on the server, cached and shared, and refreshed halfway through their lifetime; port 53 URLs (blocked by browsers) are left out. If Cloudflare is unreachable, rooms fall back to the other ICE servers and a warning is logged. `doctor` checks the key by requesting credentials.

## 1.6.0 - 2026-10-27

### Added

- Connection watchdog: links that do not connect within 15 seconds are restarted, then rebuilt from scratch (either side can ask for a fresh link), up to three attempts. After that the room says who could not be reached, with a **Retry** button, and tells moderators when no relay server is configured.
- "Connecting…" and "Not connected" badges on faces while a link is being set up or has failed.
- **Connection details** panel (`?debug=1`, or the button in a "could not connect" message): link state, route type (`host` / `srflx` / `relay`), candidates gathered, bytes sent and received, and whether STUN answered and a relay is configured.
- TURN relays with fixed credentials: `AUDIO_ROOMS_TURN_USERNAME` and `AUDIO_ROOMS_TURN_CREDENTIAL`, next to the existing shared-secret mode.
- `ice_transport_policy` (`AUDIO_ROOMS_ICE_POLICY=relay`) to force every connection through the relay.

### Fixed

- A link closed by the other side is now rebuilt right away instead of waiting for the next room change.
- Connections that stayed in "checking" or "disconnected" without ever failing were never retried.

## 1.5.2 - 2026-10-24

### Changed

- New "invited to speak" icon: a microphone broadcasting, with gently pulsing sound waves, on the person's face, the invitation card and the invite action.
- Rooms for chosen audiences show "For …" with a lock icon in the room header and on room cards.
- Neutral wording for apps of any kind (e.g. "There is no audience you can start a room for yet.").

### Fixed

- Member menus close when clicking anywhere outside them (not only outside the room), and the "Start a room" panel closes on an outside click or Escape.
- The join bar puts its explanation on its own line under the buttons.
- Opening the "Start a room" panel no longer shifts the page: the panel stays within the window and scrolls inside itself.

## 1.5.1 - 2026-10-24

### Fixed

- "Who can join" checkboxes keep their native look inside apps whose stylesheets restyle checkboxes; the package's text-field styles no longer apply to checkboxes.

## 1.5.0 - 2026-10-24

### Added

- Rooms for chosen audiences: implement `ProvidesAudienceOptions` (with `AudienceOption`) on your audience resolver to give the start-room form a **Who can join** picker. Hosts pick one or more audiences (teams, groups, communities…); only people in at least one of them, the room's hosts and monitors can see or join the room. Rooms for others never appear in lists or the "Live now" widget.
- "Everyone" (no audience) is only offered to hosts the app allows (`allowsEveryone()`).
- New table `room_audiences` (run `php artisan migrate`), `GET /api/audiences`, and an `audiences` field on `POST /api/rooms`. Room data includes `audiences` and an `audience_label` built from them.

## 1.4.1 - 2026-10-24

### Changed

- The invitation badge and card use a calm envelope icon instead of a pulsing sparkle.
- Leaving a room and asking to speak (or cancelling the request) ask for confirmation in the room's own dialog. A host leaving is told whether a co-host takes over or the room will end.
- The "Live now" widget is a wrapping grid that always fits its container (no sideways scrolling), shows at most 3 rooms on phones, and links to the full list when there are more.

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

